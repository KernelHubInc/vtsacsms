<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('DRIVER_REGISTRATION_ENABLED', false),
    'tenant_id' => env('DRIVER_REGISTRATION_TENANT_ID'),
    'organization_id' => env('DRIVER_REGISTRATION_ORGANIZATION_ID'),
];
