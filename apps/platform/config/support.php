<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SUPPORT_INTAKE_ENABLED', false),
    'public_tenant_id' => env('SUPPORT_PUBLIC_TENANT_ID') ?: env('DRIVER_REGISTRATION_TENANT_ID'),
    'contact_retention_days' => 90,
];
