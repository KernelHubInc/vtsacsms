<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Resources\Pages\CreateRecord;

final class CreateChargingStation extends CreateRecord
{
    protected static string $resource = ChargingStationResource::class;

    protected function afterCreate(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'assets.charging_station.created',
            'charging_station',
            (string) $this->record->getKey(),
            AuditResult::Succeeded,
            after: $this->record->attributesToArray(),
        ));
    }
}
