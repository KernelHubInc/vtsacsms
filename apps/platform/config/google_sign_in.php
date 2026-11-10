<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('GOOGLE_SIGN_IN_ENABLED', false),
    'tenant_id' => env('GOOGLE_SIGN_IN_TENANT_ID'),
    'server_client_id' => env('GOOGLE_SIGN_IN_SERVER_CLIENT_ID'),
    'android_client_id' => env('GOOGLE_SIGN_IN_ANDROID_CLIENT_ID'),
];
