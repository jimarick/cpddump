<?php

namespace App\Ai;

use App\Models\Profession;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The single AI pass behind "Next" on the review wizard's notes page: the
 * user's own notes plus the structured facts go in, and everything the
 * remaining pages need comes out — a short details prose, the reflection
 * answers, the takeaways (nuggets and actions), and the categorisation.
 */
class ReviewComposerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, array{key: string, label: string, question: string}>  $reflectionPrompts
     * @param  array<int, string>  $categorySlugs
     * @param  array<string, string>  $domains  code => name
     * @param  array<string, string>  $attributes  code => name
     */
    public function __construct(
        public readonly Profession $profession,
        public readonly array $reflectionPrompts,
        public readonly array $categorySlugs,
        public readonly array $domains,
        public readonly array $attributes,
    ) {}

    public static function for(User $user): self
    {
        $profession = $user->profession;

        return new self(
            profession: $profession,
            reflectionPrompts: $profession->reflectionPrompts(),
            categorySlugs: $profession->categories->pluck('slug')->all(),
            domains: $profession->frameworkDomains->pluck('name', 'code')->all(),
            attributes: $profession->frameworkDomains->flatMap(
                fn ($domain) => $domain->frameworkAttributes->pluck('name', 'code')
            )->all(),
        );
    }

    public function instructions(): Stringable|string
    {
        $frameworkName = $this->profession->settings['framework_name'] ?? 'the professional framework';

        $prompts = collect($this->reflectionPrompts)
            ->map(fn (array $p) => "- \"{$p['key']}\": {$p['question']}")
            ->implode("\n");

        $domains = collect($this->domains)
            ->map(fn ($name, $code) => "- {$code}: {$name}")
            ->implode("\n");

        $attributes = collect($this->attributes)
            ->map(fn ($name, $code) => "- {$code}: {$name}")
            ->implode("\n");

        return <<<PROMPT
        A {$this->profession->name} is reviewing one professional-development activity. You receive
        the structured facts about it, plus their own notes and reflections — typed, pasted or
        dictated, exactly as they said it. Compose the rest of the portfolio entry from that.

        Rules:
        - details: a first-person account of what the activity was — "I attended…". TWO SENTENCES
          MAXIMUM, always written, factual and specific, no meta-commentary.
        - reflection: answer each question ONLY from what the user's own words support. First
          person, natural and human — a busy clinician writing honestly, never robotic or
          grandiose. 2-5 sentences per answer. Keep every specific they mentioned and keep their
          meaning; tidy the rambling, don't replace it. If their words don't touch a question,
          set that answer to null — an honestly empty box beats confident filler.
        - nuggets: up to 10 key learning points from their notes, best first — short,
          self-contained, specific professional content worth resurfacing later. Anything the
          user emphasised (bold, capitals, highlights, headings) mattered most to them; prefer
          those lines and keep their wording, lightly tidied. Fewer good nuggets beat ten
          padded ones.
        - actions: ONLY things the user expressed intent to do, try, look up, raise or
          investigate ("TODO…", "ask…", "I should…"). A topic merely mentioned is not an
          action. Often empty.
        - Categorise against {$frameworkName} using ONLY the provided codes; choose the few that
          genuinely fit, do not scattergun.
        - Never include patient-identifiable or colleague-identifiable details anywhere.

        The reflection questions (answer each under its key in reflection):
        {$prompts}

        Framework domains:
        {$domains}

        Framework attributes:
        {$attributes}
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $reflectionProperties = collect($this->reflectionPrompts)
            ->mapWithKeys(fn ($p) => [$p['key'] => $schema->string()
                ->description("{$p['question']} Only from the user's own words; null when their notes don't answer it.")
                ->nullable()])
            ->all();

        return [
            'details' => $schema->string()->description('First-person account of the activity, two sentences maximum')->required(),
            'reflection' => $schema->object($reflectionProperties)->required(),
            'nuggets' => $schema->array()->items($schema->string())->description('Up to 10 key learning points, best first; the user\'s emphasised lines take priority')->required(),
            'actions' => $schema->array()->items($schema->string())->description('Things the user expressed intent to do or investigate — empty unless stated')->required(),
            'category_slugs' => $schema->array()->items($schema->string()->enum($this->categorySlugs))->required(),
            'domain_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->domains)))->required(),
            'attribute_codes' => $schema->array()->items($schema->string()->enum(array_keys($this->attributes)))->required(),
        ];
    }
}
