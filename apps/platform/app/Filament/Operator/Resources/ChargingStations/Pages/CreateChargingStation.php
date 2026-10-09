<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\ChargerCredentials;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateChargingStation extends CreateRecord
{
    protected static string $resource = ChargingStationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $password = (string) $data['ocpp_password'];
        unset($data['ocpp_password']);
        $record = parent::handleRecordCreation($data);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ChargerCredentials::class)->setPassword($user, (string) $record->getKey(), $password);

        return $record;
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
