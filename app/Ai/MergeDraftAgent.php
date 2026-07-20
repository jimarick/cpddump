<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * When several pieces of evidence merge into one portfolio entry, this
 * agent drafts the combined entry as a whole: title, type, organisation,
 * a single details paragraph spanning every source, and one woven answer
 * per reflection prompt — the merge modal's "AI-combined" starting point.
 *
 * @phpstan-type ReflectionPrompt array{key: string, label: string, question: string}
 */
class MergeDraftAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, ReflectionPrompt>  $reflectionPrompts
     * @param  array<int, string>  $activityTypeSlugs
     */
    public function __construct(
        public readonly string $professionName,
        public readonly array $reflectionPrompts,
        public readonly array $activityTypeSlugs,
    ) {}

    public function instructions(): Stringable|string
    {
        $prompts = collect($this->reflectionPrompts)
            ->map(fn (array $p) => "- {$p['key']}: {$p['question']}")
            ->implode("\n");

        return <<<PROMPT
        A {$this->professionName} is combining several related pieces of CPD evidence
        (for example the same meeting captured as an email, a voice note and a
        certificate, or a series of related meetings) into ONE portfolio entry. You
        receive each source's title, date, type, organisation, summary and its
        answers to the profession's reflection prompts — some drafted by AI from the
        evidence, some written by the user.

        Draft the combined entry as a whole:
        - title: short, professional and specific, describing the combined activity —
          not just one source's title. If the sources are occurrences of the same
          recurring thing, name the series (e.g. "Gynaecology MDT meetings — March").
        - activity_type_slug: the single type that best fits the combination.
        - organisation: the organisation for the combined entry, or null when the
          sources genuinely differ or none is known.
        - details: ONE factual paragraph (3-6 sentences) covering what happened
          across ALL the sources — every source must be represented; do not simply
          restate one source's summary.
        - reflection: for each prompt key, weave the sources' answers into one
          combined answer (a short paragraph, 2-6 sentences):
          - First person, natural and human — a busy clinician writing honestly.
          - The combined answer must draw on EVERY source that answered the prompt —
            never restate just one source's answer when several have content.
          - Merge overlapping points instead of repeating them; keep every distinct
            specific (names of courses, changes made, learning points).
          - Answers written by the user take precedence over AI drafts — preserve
            their wording where possible and fold the rest around it.
          - If no source has anything for a prompt, return an empty string for it.

        Never invent facts, events, dates or outcomes that appear in no source, and
        never include patient-identifiable or colleague-identifiable details.

        The reflection prompts are:
        {$prompts}
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Title for the combined entry, under 160 characters')->required(),
            'activity_type_slug' => $schema->string()->enum($this->activityTypeSlugs)->required(),
            'organisation' => $schema->string()->description('Organisation for the combined entry, or null')->nullable(),
            'details' => $schema->string()->description('One factual paragraph covering all the merged sources')->required(),
            'reflection' => $schema->object(
                collect($this->reflectionPrompts)
                    ->mapWithKeys(fn (array $p) => [
                        $p['key'] => $schema->string()->description("Combined answer for: {$p['question']}")->required(),
                    ])
                    ->all()
            )->required(),
        ];
    }
}
