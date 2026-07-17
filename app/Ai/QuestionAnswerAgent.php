<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Answers an arbitrary appraisal-form question in the user's voice,
 * grounded in their actual portfolio for the period.
 */
class QuestionAnswerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public readonly string $professionName) {}

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You draft appraisal answers for a {$this->professionName}. You receive the question their
        appraisal software asks, their portfolio for the appraisal period (activities, reflections,
        projects), and optionally their own rough notes.

        Draft the answer they would write themselves:
        - First person, natural, honest and specific — a busy clinician writing plainly, not a
          chatbot. No stock phrases, no grandiosity.
        - Ground every claim in the portfolio or the user's notes. Reference concrete activities
          by name where relevant. Never invent events, feedback, numbers or outcomes.
        - If the user's notes exist, treat them as the backbone: preserve their meaning and
          specifics, weave portfolio evidence around them.
        - Appraisers value reflection over lists: what was learned, what changed, what is next.
        - If the portfolio contains little relevant evidence, say less rather than padding —
          and note honestly (in the answer's voice) what the user might add.
        - Length: 1-3 paragraphs unless the question clearly calls for more.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->description('The drafted answer, ready to paste into appraisal software')->required(),
        ];
    }
}
