<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound Email
    |--------------------------------------------------------------------------
    */

    'inbound_email_domain' => env('INBOUND_EMAIL_DOMAIN', 'in.cpddump.com'),

    /*
    |--------------------------------------------------------------------------
    | AI Models Per Purpose
    |--------------------------------------------------------------------------
    |
    | Each provider has a heavy model (inbox analysis, Q&A, reports) and a
    | fast one (sparkle assists, weekly digest). Whichever provider is
    | active — the platform default or a user's own key — uses its pair.
    |
    */

    'ai' => [
        'models' => [
            'anthropic' => [
                'inbox_analysis' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5'),
                'text_assist' => env('AI_ANTHROPIC_MODEL_FAST', 'claude-haiku-4-5-20251001'),
                'question_answer' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5'),
                'report' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5'),
                'weekly_digest' => env('AI_ANTHROPIC_MODEL_FAST', 'claude-haiku-4-5-20251001'),
            ],
            'openai' => [
                'inbox_analysis' => env('AI_OPENAI_MODEL', 'gpt-5.4'),
                'text_assist' => env('AI_OPENAI_MODEL_FAST', 'gpt-5.4-mini'),
                'question_answer' => env('AI_OPENAI_MODEL', 'gpt-5.4'),
                'report' => env('AI_OPENAI_MODEL', 'gpt-5.4'),
                'weekly_digest' => env('AI_OPENAI_MODEL_FAST', 'gpt-5.4-mini'),
            ],
        ],

        /*
         | Soft daily output-token budget per user on the platform key.
         | Once exceeded, analysis jobs are held until the next day (or
         | the user adds their own key).
         */
        'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 200_000),

        /*
         | Truncation budget for evidence text sent to the analyst
         | (approximate characters, not tokens).
         */
        'evidence_char_limit' => (int) env('AI_EVIDENCE_CHAR_LIMIT', 60_000),
    ],
];
