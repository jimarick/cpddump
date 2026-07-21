<?php

namespace App\Ai;

use App\Enums\InboxItemStatus;
use App\Models\ActivityType;
use App\Models\Profession;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Analyses one piece of dumped evidence into a draft activity: the
 * extraction contract that approval-promotion consumes. Every slug and
 * code field is enum-constrained to the seeded reference data so that
 * promotion is a pure lookup.
 */
class InboxAnalystAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $activityTypeSlugs
     * @param  array<int, string>  $categorySlugs
     * @param  array<string, string>  $domains  code => name
     * @param  array<string, string>  $attributes  code => name
     * @param  array<int, array{key: string, label: string, question: string}>  $reflectionPrompts
     * @param  array<int, array{id: int, title: string, date: ?string, type: string}>  $recentActivities
     * @param  array<int, array{id: int, title: string}>  $openProjects
     * @param  array<int, array{id: int, title: string}>  $recurrences
     * @param  array<int, array{id: int, title: string, date: ?string, source: string, type: ?string}>  $openInboxItems
     */
    public function __construct(
        public readonly Profession $profession,
        public readonly array $activityTypeSlugs,
        public readonly array $categorySlugs,
        public readonly array $domains,
        public readonly array $attributes,
        public readonly array $reflectionPrompts,
        public readonly array $recentActivities = [],
        public readonly array $openProjects = [],
        public readonly array $recurrences = [],
        public readonly array $openInboxItems = [],
    ) {}

    public static function for(User $user, ?int $excludeInboxItemId = null): self
    {
        $profession = $user->profession;

        return new self(
            profession: $profession,
            activityTypeSlugs: ActivityType::availableTo($profession)->pluck('slug')->all(),
            categorySlugs: $profession->categories->pluck('slug')->all(),
            domains: $profession->frameworkDomains->pluck('name', 'code')->all(),
            attributes: $profession->frameworkDomains->flatMap(
                fn ($domain) => $domain->frameworkAttributes->pluck('name', 'code')
            )->all(),
            reflectionPrompts: $profession->reflectionPrompts(),
            recentActivities: $user->activities()
                ->latest('starts_on')
                ->limit(40)
                ->with('type')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'date' => $a->starts_on?->toDateString(),
                    'type' => $a->type->slug,
                ])
                ->all(),
            openProjects: $user->projects()
                ->where('status', 'open')
                ->get()
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])
                ->all(),
            recurrences: $user->recurrences()
                ->where('is_active', true)
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title])
                ->all(),
            // Compact one-liners only (~15 tokens each, capped at 25) —
            // enough for "same MDT?" matching without shipping content.
            openInboxItems: $user->inboxItems()
                ->where('status', InboxItemStatus::Ready)
                ->when($excludeInboxItemId !== null, fn ($q) => $q->whereKeyNot($excludeInboxItemId))
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => $i->ai_analysis['title']
                        ?? $i->raw_payload['title']
                        ?? $i->raw_payload['subject']
                        ?? 'untitled',
                    'date' => $i->ai_analysis['starts_on'] ?? $i->created_at->toDateString(),
                    'source' => $i->source->value,
                    'type' => $i->ai_analysis['activity_type_slug'] ?? null,
                ])
                ->all(),
        );
    }

    public function instructions(): Stringable|string
    {
        $frameworkName = $this->profession->settings['framework_name'] ?? 'the professional framework';
        $pointsHeuristic = $this->profession->settings['points_heuristic'] ?? 'Roughly one CPD point per hour.';

        $domains = collect($this->domains)
            ->map(fn ($name, $code) => "- {$code}: {$name}")
            ->implode("\n");

        $attributes = collect($this->attributes)
            ->map(fn ($name, $code) => "- {$code}: {$name}")
            ->implode("\n");

        $reflectionGuide = collect($this->reflectionPrompts)
            ->map(fn ($p) => "- \"{$p['key']}\": {$p['question']}")
            ->implode("\n");

        $recent = collect($this->recentActivities)
            ->map(fn ($a) => "- id {$a['id']}: {$a['title']} ({$a['type']}, {$a['date']})")
            ->implode("\n") ?: '(none)';

        $projects = collect($this->openProjects)
            ->map(fn ($p) => "- id {$p['id']}: {$p['title']}")
            ->implode("\n") ?: '(none)';

        $regulars = collect($this->recurrences)
            ->map(fn ($r) => "- id {$r['id']}: {$r['title']}")
            ->implode("\n") ?: '(none)';

        $waiting = collect($this->openInboxItems)
            ->map(fn ($i) => "- id {$i['id']}: {$i['title']} ({$i['source']}, ".($i['type'] ?? '?').", {$i['date']})")
            ->implode("\n") ?: '(none)';

        return <<<PROMPT
        You are the evidence analyst for CPD Dump, a portfolio tool for a {$this->profession->name}.
        You receive one piece of raw professional-development evidence (a forwarded email, an uploaded
        certificate or document, a calendar event, a transcribed voice note, a pasted article, or a
        manual note). Turn it into one polished draft portfolio entry.

        Rules:
        - Title: short, professional, specific (e.g. "Advanced Life Support — recertification"), never
          the raw email subject clutter.
        - CPD points: {$pointsHeuristic} Estimate conservatively from the evidence; use 0 if genuinely
          no learning time is evidenced.
        - Dates must come from the evidence, not be invented. Use null when unknown.
        - summary: write it in the first person, as if the user wrote it themselves — "I attended…",
          "I completed…". Natural and human, TWO SENTENCES MAXIMUM: what it was and what it
          covered. Never write meta-commentary: no "the evidence indicates/suggests", no remarks
          about missing information, unreadable files, sources, or what the evidence shows. Anything
          missing or unreadable belongs ONLY in missing_evidence, never in the summary.
        - user_notes: the user's OWN words found in the evidence, copied out VERBATIM — the
          commentary they typed above a forwarded email, their voice-note transcript, notes they
          added alongside a file. Reproduce their words exactly (their typos included); never
          summarise, never rewrite, and never include third-party content (the forwarded email
          itself, a newsletter, a page's text). Null when the evidence contains no words of the
          user's own. Exception to the identifier rule: if their words contain an identifier,
          replace just that identifier with [removed].
        - Reflections are NOT like the summary: they are the user's personal reflection, and you
          must never write them from nothing. Search the evidence for the user's OWN reflective
          words — commentary they typed above a forwarded email ("really useful day…", "I'll start
          doing X"), musings in a voice note, notes they added alongside a file. When you find
          some, shape THOSE words into the matching reflection answers: first person, natural and
          human — like a busy clinician writing honestly — 2-5 sentences, keeping their specifics
          and meaning. Fill ONLY the answers their words actually support and set the rest to
          null. If the evidence contains no reflection from the user (a bare certificate, an
          agenda, a voice note that only states facts like "there was an MDT on Tuesday"), set
          EVERY reflection answer to null — the app invites the user to reflect in their own
          words instead. Factual statements about an event are not reflection.
        - reflection_source: when you filled any reflection answer, one short line saying where
          the user's reflection came from, quoting a few of their own words — e.g. "From your
          forwarded email — 'really useful day…'". Null when every reflection answer is null.
        - nuggets: up to 10 key learning points from this evidence, best first — short,
          self-contained, specific professional content, each worth resurfacing to the user later
          ("Prasugrel is contraindicated after TIA", never "the course was informative"). When the
          evidence is the user's own notes (source "debrief"), their emphasis is your strongest
          signal: anything they bolded, underlined, highlighted, wrote in capitals or set off under
          a heading mattered to them — prefer those lines, keeping the user's wording and
          specifics (light tidying of punctuation only). Fewer good nuggets beat ten padded ones.
        - actions: ONLY things the user themselves expressed intent to do, try, look up, raise or
          investigate ("TODO…", "ask the department…", "look up…", "I should…"). A topic merely
          being mentioned is not an action. Usually empty for evidence that isn't the user's own
          notes.
        - If the evidence includes a user-supplied "occurred_on" date, it is authoritative — use
          it as starts_on even if fetched page content suggests another date.
        - A debrief's "notes" are the user's own words: they may also contain reflective
          commentary, which counts as the user's own reflection for the rules above.
        - Categorise against {$frameworkName} using ONLY the provided codes. Choose the few that
          genuinely fit; do not scattergun.
        - Patient safety: if the evidence contains anything that could identify a patient, colleague
          or third party (names, NHS numbers, dates of birth, addresses, unique case details), flag
          each instance in pii_flags with a short excerpt. Do NOT reproduce identifiers in your
          drafted text — write around them.
        - If evidence appears to duplicate one of the user's recent activities, add its id to
          possible_duplicate_activity_ids.
        - If supporting evidence seems missing (e.g. a course email with no certificate), note what
          in missing_evidence.
        - confidence: 0-1, how confident you are the extraction is correct overall.

        Reflection questions (answer each under its key in reflection_draft):
        {$reflectionGuide}

        Framework domains:
        {$domains}

        Framework attributes:
        {$attributes}

        The user's recent activities (for duplicate detection and linking):
        {$recent}

        The user's open projects/objectives (suggest links only when clearly relevant):
        {$projects}

        The user's declared regular activities (recurring fixtures and yearly expectations). If this
        evidence IS an occurrence of one of them (e.g. an email about that very meeting), set
        matched_recurrence_id to its id — otherwise null. An email merely mentioning it does not count.
        {$regulars}

        Other evidence currently waiting in the user's inbox:
        {$waiting}

        Related evidence: if this evidence appears to be part of the SAME event, course, meeting or
        project as any waiting inbox item or recent activity above — same title stem, same
        organisation, or dates within about a week of each other — list those ids in
        possible_related_inbox_item_ids / possible_related_activity_ids and give a one-line
        related_reason. "Related" means the user could sensibly combine them into one portfolio
        entry; it is NOT the same as a duplicate (exact duplicates of an activity still go in
        possible_duplicate_activity_ids). Items captured within a day or two of each other are more
        likely related than ones months apart. Leave the lists empty when nothing genuinely matches.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $reflectionProperties = collect($this->reflectionPrompts)
            ->mapWithKeys(fn ($p) => [$p['key'] => $schema->string()
                ->description("{$p['question']} Only from the user's own reflective words in the evidence; null when their words don't answer it.")
                ->nullable()])
            ->all();

        return [
            'title' => $schema->string()->description('Short, professional title, under 160 characters')->required(),
            'activity_type_slug' => $schema->string()->enum($this->activityTypeSlugs)->required(),
            'starts_on' => $schema->string()->description('ISO date YYYY-MM-DD')->nullable(),
            'ends_on' => $schema->string()->description('ISO date YYYY-MM-DD')->nullable(),
            'organisation' => $schema->string()->nullable(),
            'cpd_points' => $schema->number()->description('Estimated CPD points, ~1 per hour')->required(),
            'summary' => $schema->string()->description('First-person account (two sentences maximum) written as the user — "I attended…" — describing the activity itself, with no meta-commentary about the evidence or missing information')->required(),
            'user_notes' => $schema->string()->description('The user\'s own words from the evidence, verbatim — their email commentary, voice transcript or added notes; never third-party content; null when they wrote nothing themselves')->nullable(),
            'nuggets' => $schema->array()->items($schema->string())->description('Up to 10 key learning points, best first — short, self-contained, worth resurfacing later; the user\'s own emphasised lines take priority')->required(),
            'actions' => $schema->array()->items($schema->string())->description('Things the user expressed intent to do, try, look up or investigate — empty unless the user stated intent')->required(),
            'reflection_draft' => $schema->object($reflectionProperties)->required(),
            'reflection_source' => $schema->string()->description('Where the user\'s reflection came from, quoting a few of their words — null when every reflection answer is null')->nullable(),
            'category_slugs' => $schema->array()->items($schema->string()->enum($this->categorySlugs))->required(),
            'domain_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->domains)))->required(),
            'attribute_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->attributes)))->required(),
            'suggested_project_ids' => $schema->array()->items($schema->integer())->required(),
            'possible_duplicate_activity_ids' => $schema->array()->items($schema->integer())->required(),
            'possible_related_inbox_item_ids' => $schema->array()->items($schema->integer())->description('Waiting inbox items that appear to be about the same event/course/project')->required(),
            'possible_related_activity_ids' => $schema->array()->items($schema->integer())->description('Recent activities that appear to be about the same event/course/project (not exact duplicates)')->required(),
            'related_reason' => $schema->string()->description('One line on why the related ids match, or null')->nullable(),
            'matched_recurrence_id' => $schema->integer()->description('Id of the regular activity this evidence is an occurrence of, or null')->nullable(),
            'confidence' => $schema->number()->description('Between 0 and 1: overall confidence in this extraction')->required(),
            'pii_flags' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('e.g. patient_name, nhs_number, dob, address, case_details')->required(),
                'excerpt' => $schema->string()->description('Short excerpt showing the identifier context')->required(),
                'severity' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            ]))->required(),
            'missing_evidence' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
