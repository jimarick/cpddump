<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The "shape into reflections" button behind the talk-first capture box:
 * turns one honest ramble (dictated or typed) into the profession's
 * reflection answers, filling only the answers the ramble actually
 * supports — the per-question counterpart of TextAssistAgent.
 */
class ReflectionDraftAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, array{key: string, label: string, question: string}>  $reflectionPrompts
     */
    public function __construct(
        public readonly string $professionName,
        public readonly array $reflectionPrompts,
    ) {}

    public function instructions(): Stringable|string
    {
        $prompts = collect($this->reflectionPrompts)
            ->map(fn (array $p) => "- \"{$p['key']}\": {$p['question']}")
            ->implode("\n");

        return <<<PROMPT
        A {$this->professionName} has talked (or typed) an honest ramble reflecting on one
        professional activity. Turn that ramble into their appraisal reflection answers.

        Rules:
        - Answer each question ONLY from what the user's own words support. First person,
          natural and human — a busy clinician writing honestly, never robotic or grandiose.
          2-5 sentences per answer, in well-formed prose.
        - Keep every specific the user mentioned (courses, cases, changes made) and keep
          their meaning; tidy the rambling, don't replace it.
        - Never invent facts, learning or intentions the user didn't express. The activity
          context is for grounding references only, not new material. Exception: any "key
          learning points" or "action points" in the context are the user's own words — you
          may weave those into the answers they genuinely support.
        - If the ramble doesn't touch a question at all, set that answer to null — an
          honestly empty box beats confident filler.
        - Never include patient-identifiable or colleague-identifiable details.

        The reflection questions (answer each under its key in reflection):
        {$prompts}
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reflection' => $schema->object(
                collect($this->reflectionPrompts)
                    ->mapWithKeys(fn (array $p) => [$p['key'] => $schema->string()
                        ->description("{$p['question']} Only from the user's own words; null when the ramble doesn't answer it.")
                        ->nullable()])
                    ->all()
            )->required(),
        ];
    }
}
