<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The "Generate takeaways" button on an existing activity: everything the
 * entry records goes in, and just the takeaways come out — for entries
 * whose takeaways were skipped at review and wanted later.
 */
class TakeawayExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public readonly string $professionName) {}

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You receive one professional-development activity from a {$this->professionName}'s
        portfolio: its details, their own notes and their reflections. Extract the takeaways
        worth resurfacing to them later.

        Rules:
        - nuggets: up to 10 key learning points, best first — short, self-contained, specific
          professional content worth being reminded of ("Prasugrel is contraindicated after
          TIA", never "the course was informative"). Anything the user emphasised in their
          notes (bold, capitals, highlights, headings) mattered most to them; prefer those
          lines and keep their wording, lightly tidied. Fewer good nuggets beat ten padded
          ones.
        - actions: ONLY things the user expressed intent to do, try, look up, raise or
          investigate ("TODO…", "ask…", "I should…"). A topic merely mentioned is not an
          action. Often empty.
        - Never include patient-identifiable or colleague-identifiable details.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'nuggets' => $schema->array()->items($schema->string())->description('Up to 10 key learning points, best first')->required(),
            'actions' => $schema->array()->items($schema->string())->description('Things the user expressed intent to do or investigate — empty unless stated')->required(),
        ];
    }
}
