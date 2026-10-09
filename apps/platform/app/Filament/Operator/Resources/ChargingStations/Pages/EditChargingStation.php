<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations\Pages;

use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\ChargerCredentials;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditChargingStation extends EditRecord
{
    protected static string $resource = ChargingStationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $password = (string) ($data['ocpp_password'] ?? '');
        unset($data['ocpp_password']);
        $record = parent::handleRecordUpdate($record, $data);
        if ($password !== '') {
            $user = auth()->user();
            abort_unless($user instanceof User, 403);
            app(ChargerCredentials::class)->setPassword($user, (string) $record->getKey(), $password);
        }
        $this->data['ocpp_password'] = null;

        return $record;
    }

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
