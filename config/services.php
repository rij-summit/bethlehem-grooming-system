<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),

        'base_url' => env(
            'GROQ_BASE_URL',
            'https://api.groq.com/openai/v1'
        ),

        'model' => env(
            'GROQ_MODEL',
            'openai/gpt-oss-20b'
        ),

        'reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low'),

        'include_reasoning' => filter_var(
            env('GROQ_INCLUDE_REASONING', false),
            FILTER_VALIDATE_BOOLEAN
        ),

        'max_completion_tokens' => (int) env(
            'GROQ_MAX_COMPLETION_TOKENS',
            1024
        ),
    ],

];
