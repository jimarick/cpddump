<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'inbound_webhook_secret' => env('RESEND_INBOUND_WEBHOOK_SECRET'),
    ],

    /*
     | Outbound mail via SES. Deliberately NOT the AWS_* env names —
     | Laravel Cloud injects those for its R2 object storage.
     */
    'ses' => [
        'key' => env('SES_KEY', env('SES_INBOUND_KEY')),
        'secret' => env('SES_SECRET', env('SES_INBOUND_SECRET')),
        'region' => env('SES_REGION', env('SES_INBOUND_REGION', 'eu-west-2')),
    ],

    /*
     | Inbound mail: SES receipt rules drop raw emails into this bucket;
     | the app fetches, parses, ingests and deletes them.
     */
    'ses_inbound' => [
        'key' => env('SES_INBOUND_KEY'),
        'secret' => env('SES_INBOUND_SECRET'),
        'region' => env('SES_INBOUND_REGION', 'eu-west-2'),
        'bucket' => env('SES_INBOUND_BUCKET'),
        'verify_signature' => (bool) env('SES_VERIFY_SIGNATURE', true),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
