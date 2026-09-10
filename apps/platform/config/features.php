<?php

declare(strict_types=1);

return [
    'ocpp' => (bool) env('FEATURE_OCPP', false),
    'remote_charging' => (bool) env('FEATURE_REMOTE_CHARGING', false),
    'real_payments' => (bool) env('FEATURE_REAL_PAYMENTS', false),
    'settlements' => (bool) env('FEATURE_SETTLEMENTS', false),
    'ocpi' => (bool) env('FEATURE_OCPI', false),
    'store_locator' => (bool) env('FEATURE_STORE_LOCATOR', true),
    'inventory' => (bool) env('FEATURE_INVENTORY', true),
    'maintenance' => (bool) env('FEATURE_MAINTENANCE', true),
    'procurement' => (bool) env('FEATURE_PROCUREMENT', true),
    'demo_mode' => (bool) env('FEATURE_DEMO_MODE', false),
    'simulated_charging' => (bool) env('FEATURE_SIMULATED_CHARGING', false),
    'simulated_payments' => (bool) env('FEATURE_SIMULATED_PAYMENTS', false),
];
