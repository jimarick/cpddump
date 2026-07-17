<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Anthropic is the platform default for text generation and analysis.
    | Transcription uses OpenAI (Anthropic has no transcription API).
    | Users may bring their own OpenAI or Anthropic key, applied per
    | request by App\Services\AiGateway.
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
    'default_for_transcription' => 'openai',

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', false),
        ],
    ],
];
