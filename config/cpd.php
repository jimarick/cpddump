<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Flip off to close the doors: /register shows a "coming soon" page and
    | new sign-ups are refused, while existing accounts log in as normal.
    |
    */

    'registration_open' => (bool) env('CPD_REGISTRATION_OPEN', true),

    /*
    |--------------------------------------------------------------------------
    | Inbound Email
    |--------------------------------------------------------------------------
    */

    'inbound_email_domain' => env('INBOUND_EMAIL_DOMAIN', 'in.cpddump.com'),

    /*
     | Local parts on the receiving domain that are humans, not dump
     | tokens — inbound mail to these is relayed to contact_email.
     */
    'inbound_aliases' => ['hello', 'support', 'contact', 'dmarc'],

    'contact_email' => env('CPD_CONTACT_EMAIL', 'james.ricketts@gmail.com'),

    /*
    |--------------------------------------------------------------------------
    | Ingestion Limits
    |--------------------------------------------------------------------------
    |
    | New inbox items beyond the daily cap are still stored, but their AI
    | analysis is deferred until the next day. Limits what a leaked dump
    | address (or a runaway calendar) can burn.
    |
    */

    'ingest' => [
        'daily_item_cap' => (int) env('CPD_DAILY_ITEM_CAP', 40),

        /*
         | The only file types stored from any source. Email attachments
         | outside this list are silently skipped (no .exe in the
         | evidence folder, however it's renamed).
         */
        // "Text" PDFs above this size are really scan hybrids — route them
        // through rasterisation like any scanned document.
        'text_pdf_max_bytes' => env('CPD_TEXT_PDF_MAX_BYTES', 10_485_760),

        'allowed_extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif',
            'tiff', 'tif', 'avif', 'bmp',
            'doc', 'docx', 'ppt', 'pptx', 'txt', 'ics',
        ],
    ],

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
         | Soft daily budgets per user on the platform key. Output and
         | input tokens are capped separately (attachments drive input);
         | exceeding either holds AI work until the next day (or the
         | user adds their own key).
         */
        'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 200_000),
        'daily_input_token_budget' => (int) env('AI_DAILY_INPUT_TOKEN_BUDGET', 1_000_000),

        /*
         | Hard ceiling on total platform-key tokens (input + output)
         | across ALL users per day — the kill-switch against
         | multi-account abuse. BYO-key users are unaffected.
         */
        'platform_daily_token_budget' => (int) env('AI_PLATFORM_DAILY_TOKEN_BUDGET', 10_000_000),

        /*
         | Image-only (scanned) PDFs above this page count are not sent
         | raw to the model on the platform key — each page costs
         | thousands of input tokens.
         */
        'max_scanned_pdf_pages' => (int) env('AI_MAX_SCANNED_PDF_PAGES', 20),

        /*
         | Truncation budget for evidence text sent to the analyst
         | (approximate characters, not tokens).
         */
        'evidence_char_limit' => (int) env('AI_EVIDENCE_CHAR_LIMIT', 60_000),
    ],
];
