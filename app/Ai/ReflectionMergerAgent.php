<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * When several pieces of evidence merge into one portfolio entry, this
 * agent weaves their separate reflections into a single answer per
 * reflection prompt — the merge modal's "AI-combined" starting point.
 *
 * @phpstan-type ReflectionPrompt array{key: string, label: string, question: string}
 */
class ReflectionMergerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, ReflectionPrompt>  $reflectionPrompts
     */
    public function __construct(
        public readonly string $professionName,
        public readonly array $reflectionPrompts,
    ) {}

    public function instructions(): Stringable|string
    {
        $prompts = collect($this->reflectionPrompts)
            ->map(fn (array $p) => "- {$p['key']}: {$p['question']}")
            ->implode("\n");

        return <<<PROMPT
        A {$this->professionName} is combining several related pieces of CPD evidence
        (for example the same meeting captured as an email, a voice note and a
        certificate) into one portfolio entry. You receive each source's title, date
        and its answers to the profession's reflection prompts — some drafted by AI
        from the evidence, some written by the user.

        The reflection prompts are:
        {$prompts}

        For each prompt key, weave the sources' answers into ONE combined answer:
        - First person, natural and human — a busy clinician writing honestly.
        - The combined answer must draw on EVERY source that answered the prompt —
          never restate just one source's answer when several have content.
        - Merge overlapping points instead of repeating them; keep every distinct
          specific (names of courses, changes made, learning points).
        - Answers written by the user take precedence over AI drafts — preserve
          their wording where possible and fold the rest around it.
        - Do not invent facts, events, dates or outcomes that appear in no source.
        - If no source has anything for a prompt, return an empty string for it.
        - Length: 2-6 sentences per answer.
        - Never include patient-identifiable or colleague-identifiable details.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
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
