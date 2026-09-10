<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum SessionAnomaly: string
{
    case ClockSkew = 'clock_skew';
    case ConflictingTransaction = 'conflicting_transaction';
    case InvalidMeterValue = 'invalid_meter_value';
    case MeterReset = 'meter_reset';
    case MissingStartMeter = 'missing_start_meter';
    case MissingStopMeter = 'missing_stop_meter';
    case OutOfOrderMeter = 'out_of_order_meter';
    case ReconstructedAfterReconnect = 'reconstructed_after_reconnect';
    case TariffMissing = 'tariff_missing';
}
