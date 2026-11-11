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

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => env('STRIPE_API_VERSION'),
        // Price ricorrente annuale della membership (price_...), creato nella Dashboard Stripe.
        'membership_price' => env('STRIPE_MEMBERSHIP_PRICE'),
        // Fattura Stripe sui biglietti (invoice_creation) con indirizzo di fatturazione e partita IVA facoltativa.
        // Solo parametri tecnici: IVA e Stripe Tax li decide il cliente.
        'invoices' => (bool) env('STRIPE_INVOICES', true),
        'invoice_description' => env('STRIPE_INVOICE_DESCRIPTION'),
        'invoice_footer' => env('STRIPE_INVOICE_FOOTER'),
    ],

];
