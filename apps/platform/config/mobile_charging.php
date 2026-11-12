<?php

declare(strict_types=1);

return [
    // Pilot limit is disclosed before the driver reserves real prepaid funds.
    'maximum_reservation_minor' => (int) env('MOBILE_CHARGING_MAXIMUM_RESERVATION_MINOR', 100),
    'plug_before_start_stations' => array_map('trim', explode(',', (string) env('MOBILE_CHARGING_PLUG_BEFORE_START_STATIONS', ''))),
    'quote_seconds' => 120,
    'meter_timeout_seconds' => 60,
    'stop_buffer_seconds' => 90,
    'stop_retry_seconds' => 30,
    'stop_confirmation_seconds' => 60,
    'stop_maximum_attempts' => 3,
];
