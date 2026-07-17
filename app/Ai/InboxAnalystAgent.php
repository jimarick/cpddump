<?php

namespace App\Ai;

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
    ) {}

    public static function for(User $user): self
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
        - Summarise what happened and extract genuine learning points.
        - Draft the reflection answers in the first person, natural and human-sounding — like a busy
          clinician writing honestly, not a chatbot. Ground every claim in the evidence. Keep each
          answer to 2-5 sentences.
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
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $reflectionProperties = collect($this->reflectionPrompts)
            ->mapWithKeys(fn ($p) => [$p['key'] => $schema->string()->description($p['question'])->required()])
            ->all();

        return [
            'title' => $schema->string()->max(160)->required(),
            'activity_type_slug' => $schema->string()->enum($this->activityTypeSlugs)->required(),
            'starts_on' => $schema->string()->description('ISO date YYYY-MM-DD')->nullable(),
            'ends_on' => $schema->string()->description('ISO date YYYY-MM-DD')->nullable(),
            'organisation' => $schema->string()->nullable(),
            'cpd_points' => $schema->number()->description('Estimated CPD points, ~1 per hour')->required(),
            'summary' => $schema->string()->description('2-4 sentence factual summary of the activity')->required(),
            'suggested_learning_points' => $schema->array()->items($schema->string())->required(),
            'reflection_draft' => $schema->object($reflectionProperties)->required(),
            'category_slugs' => $schema->array()->items($schema->string()->enum($this->categorySlugs))->required(),
            'domain_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->domains)))->required(),
            'attribute_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->attributes)))->required(),
            'suggested_project_ids' => $schema->array()->items($schema->integer())->required(),
            'possible_duplicate_activity_ids' => $schema->array()->items($schema->integer())->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'pii_flags' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('e.g. patient_name, nhs_number, dob, address, case_details')->required(),
                'excerpt' => $schema->string()->description('Short excerpt showing the identifier context')->required(),
                'severity' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            ]))->required(),
            'missing_evidence' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
