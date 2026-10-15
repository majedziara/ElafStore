<?php

return [
    'webhook_base_url' => rtrim(env('PAYMENT_WEBHOOK_BASE_URL', env('APP_URL', 'http://localhost:8000')), '/'),
    'paypal_enabled' => env('PAYPAL_ENABLED', true),
    'stripe' => [
        'enabled' => env('STRIPE_ENABLED', true),
        'mode' => env('STRIPE_MODE', 'sandbox'),
        'api_version' => '2025-06-30.basil',
        'sandbox' => [
            'secret_key' => env('STRIPE_SANDBOX_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_SANDBOX_WEBHOOK_SECRET'),
        ],
        'live' => [
            'secret_key' => env('STRIPE_LIVE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_LIVE_WEBHOOK_SECRET'),
        ],
    ],
    'triple-a' => [
        'enabled' => env('TRIPLEA_ENABLED', true),
        'mode' => env('TRIPLEA_MODE', 'sandbox'),
        'sandbox' => [
            'client_id' => env('TRIPLEA_SANDBOX_CLIENT_ID'),
            'client_secret' => env('TRIPLEA_SANDBOX_CLIENT_SECRET'),
            'merchant_key' => env('TRIPLEA_SANDBOX_MERCHANT_KEY'),
            'notify_secret' => env('TRIPLEA_SANDBOX_NOTIFY_SECRET'),
        ],
        'live' => [
            'client_id' => env('TRIPLEA_LIVE_CLIENT_ID'),
            'client_secret' => env('TRIPLEA_LIVE_CLIENT_SECRET'),
            'merchant_key' => env('TRIPLEA_LIVE_MERCHANT_KEY'),
            'notify_secret' => env('TRIPLEA_LIVE_NOTIFY_SECRET'),
        ],
    ],
];
