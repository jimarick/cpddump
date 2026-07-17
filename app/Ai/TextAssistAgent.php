<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The sparkle button behind every free-text box: expands a few words into
 * a polished, human-sounding paragraph, or drafts from scratch using the
 * surrounding activity context.
 */
class TextAssistAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public readonly string $professionName) {}

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You help a {$this->professionName} write portfolio and appraisal text. You receive a field
        label (what the text box is for), optional context about the activity being described, and
        the user's current text — which may be rough notes, a few words, or empty.

        Rewrite or draft the field content:
        - First person, natural and human — like a busy clinician writing honestly, never robotic
          or grandiose. No "As a dedicated professional…" clichés.
        - Appraisers value specifics and honest reflection over polish. Keep claims grounded in
          what the user actually wrote or the provided context; do not invent facts, events,
          dates or outcomes.
        - Expand rough notes into flowing sentences; keep the user's meaning and any specifics
          they mention.
        - If the current text is empty, draft something plausible and modest from the context
          alone, and keep it short so the user can correct it.
        - Length: a short paragraph (2-5 sentences) unless the current text warrants more.
        - Never include patient-identifiable or colleague-identifiable details.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The improved or drafted field text')->required(),
        ];
    }
}
