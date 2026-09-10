<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Resources\Pages\EditRecord;

final class EditChargingStation extends EditRecord
{
    protected static string $resource = ChargingStationResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = $this->getRecord()->attributesToArray();
    }

    protected function afterSave(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'assets.charging_station.updated',
            'charging_station',
            (string) $this->getRecord()->getKey(),
            AuditResult::Succeeded,
            before: $this->before,
            after: $this->getRecord()->fresh()->attributesToArray(),
        ));
    }
}
