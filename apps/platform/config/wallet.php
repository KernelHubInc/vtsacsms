<?php

declare(strict_types=1);

return [
    'mode' => env('WALLET_MODE', 'disabled'),
    // Limits are deployment decisions; no live collection without explicit configuration.
    'minimum_minor' => (int) env('WALLET_MINIMUM_MINOR', 100),
    'maximum_minor' => (int) env('WALLET_MAXIMUM_MINOR', 100000),
    'aub' => [
        'approved' => (bool) env('AUB_QRPH_APPROVED', false),
        'tenant_id' => env('AUB_TENANT_ID'),
        'merchant_id' => env('AUB_MERCHANT_ID'),
        'signing_key' => env('AUB_SIGNING_KEY'),
        'server_ip' => env('AUB_SERVER_IP'),
        'notify_url' => env('AUB_NOTIFY_URL'),
    ],
];
