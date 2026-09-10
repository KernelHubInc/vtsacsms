<?php

declare(strict_types=1);

return [
    'default_provider' => env('PAYMENT_PROVIDER', 'fake'),
    'fake' => ['webhook_secret' => env('FAKE_PAYMENT_WEBHOOK_SECRET')],
    'stripe' => [
        'base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com'),
        'secret_key' => env('STRIPE_TEST_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        'timeout_seconds' => (int) env('STRIPE_TIMEOUT_SECONDS', 20),
        'webhook_tolerance_seconds' => (int) env('PAYMENT_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
];
