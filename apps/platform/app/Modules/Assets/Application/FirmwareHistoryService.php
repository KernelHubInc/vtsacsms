<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\ChargingStationFirmwareHistory;
use App\Modules\Assets\Domain\Models\FirmwareVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FirmwareHistoryService
{
    public function install(ChargingStation $station, FirmwareVersion $firmware, CarbonImmutable $effectiveFrom): ChargingStationFirmwareHistory
    {
        if ($station->charger_model_id !== null && $station->charger_model_id !== $firmware->charger_model_id) {
            throw ValidationException::withMessages(['firmware_version_id' => 'Firmware does not belong to the station model.']);
        }

        return DB::transaction(function () use ($station, $firmware, $effectiveFrom): ChargingStationFirmwareHistory {
            $current = ChargingStationFirmwareHistory::query()
                ->where('charging_station_id', $station->getKey())
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current !== null && $effectiveFrom->lessThanOrEqualTo($current->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'Effective time must be after the current firmware start.']);
            }

            $current?->update(['effective_to' => $effectiveFrom]);

            return ChargingStationFirmwareHistory::query()->create([
                'tenant_id' => $station->tenant_id,
                'charging_station_id' => $station->getKey(),
                'firmware_version_id' => $firmware->getKey(),
                'effective_from' => $effectiveFrom,
            ]);
        });
    }
}
