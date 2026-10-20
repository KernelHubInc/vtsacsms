<?php

declare(strict_types=1);

return [
    // Pilot limit is disclosed before the driver reserves real prepaid funds.
    'maximum_reservation_minor' => (int) env('MOBILE_CHARGING_MAXIMUM_RESERVATION_MINOR', 100),
    'quote_seconds' => 120,
    'meter_timeout_seconds' => 60,
    'stop_buffer_seconds' => 90,
];
