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
    | Model used for each AI purpose when the provider is the platform
    | default (Anthropic). BYO-key users get the same model names for
    | Anthropic, or the OpenAI equivalents below.
    |
    */

    'ai' => [
        'models' => [
            'anthropic' => [
                'inbox_analysis' => env('AI_MODEL_ANALYSIS', 'claude-sonnet-5'),
                'text_assist' => env('AI_MODEL_TEXT', 'claude-haiku-4-5-20251001'),
                'question_answer' => env('AI_MODEL_QA', 'claude-sonnet-5'),
                'report' => env('AI_MODEL_REPORT', 'claude-sonnet-5'),
                'weekly_digest' => env('AI_MODEL_DIGEST', 'claude-haiku-4-5-20251001'),
            ],
            'openai' => [
                'inbox_analysis' => 'gpt-5.2',
                'text_assist' => 'gpt-5.2-mini',
                'question_answer' => 'gpt-5.2',
                'report' => 'gpt-5.2',
                'weekly_digest' => 'gpt-5.2-mini',
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
