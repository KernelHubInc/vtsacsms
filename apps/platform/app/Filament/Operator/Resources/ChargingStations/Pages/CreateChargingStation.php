<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

final class CreateChargingStation extends CreateRecord
{
    protected static string $resource = ChargingStationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(CurrentTenant::class)->get()->tenantId;
        Gate::authorize('update', new ChargingStation($data));

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('setup', ['record' => $this->record]);
    }

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
