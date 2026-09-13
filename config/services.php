<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | STRIPE_KEY        — publishable key (pk_live_... or pk_test_...)
    | STRIPE_SECRET     — secret key (sk_live_... or sk_test_...)
    | STRIPE_WEBHOOK_SECRET — from `stripe listen` or the Stripe dashboard webhook
    |
    | STRIPE_PLATFORM_FEE_PERCENT — optional platform cut, e.g. 5 = 5%.
    |   Applied as application_fee_amount on the PaymentIntent when a freelancer
    |   has already connected their account at funding time.
    |   Set to 0 to disable platform fees.
    |
    */
    'stripe' => [
        'key'                  => env('STRIPE_KEY'),
        'secret'               => env('STRIPE_SECRET'),
        'webhook_secret'       => env('STRIPE_WEBHOOK_SECRET'),
        'platform_fee_percent' => env('STRIPE_PLATFORM_FEE_PERCENT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (Claude AI)
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pusher (Real-time broadcasts)
    |--------------------------------------------------------------------------
    */
    'pusher' => [
        'app_id'  => env('PUSHER_APP_ID'),
        'app_key' => env('PUSHER_APP_KEY'),
        'secret'  => env('PUSHER_APP_SECRET'),
        'options' => [
            'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            'useTLS'  => true,
        ],
    ],

];
