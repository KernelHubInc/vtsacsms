<?php

declare(strict_types=1);

return [
    // Must exceed the gateway's negotiated heartbeat interval plus delivery jitter.
    'connection_stale_after_seconds' => (int) env('OCPP_CONNECTION_STALE_AFTER_SECONDS', 180),
];
