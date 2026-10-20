<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\Evse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class ProvisionChargingEquipment
{
    /** @param array<string, mixed> $data */
    public function evse(User $actor, array $data): Evse
    {
        $data = Validator::make($data, [
            'charging_station_id' => ['required', 'ulid'],
            'evse_number' => ['required', 'integer', 'between:1,65535'],
            'uid' => ['nullable', 'string', 'max:100'],
            'lifecycle_status' => ['required', Rule::in(['draft', 'active'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): Evse {
            $station = ChargingStation::query()->lockForUpdate()->whereKey($data['charging_station_id'])->firstOrFail();
            Gate::forUser($actor)->authorize('update', $station);
            if ($station->evses()->where('evse_number', $data['evse_number'])->exists()) {
                throw ValidationException::withMessages(['evse_number' => 'This station already has that EVSE number. Open the existing EVSE or use the actual number of the new outlet.']);
            }
            $evse = Evse::query()->create($data + ['tenant_id' => $station->tenant_id]);
            $this->audit($evse, 'evse');

            return $evse;
        });
    }

    /** @param array<string, mixed> $data */
    public function connector(User $actor, array $data): Connector
    {
        $data = Validator::make($data, [
            'evse_id' => ['required', 'ulid'],
            'connector_number' => ['required', 'integer', 'between:1,65535'],
            'connector_standard_id' => ['required', 'ulid', Rule::exists('connector_standards', 'id')->whereNull('archived_at')],
            'charging_current_type_id' => ['required', 'ulid', Rule::exists('charging_current_types', 'id')->whereNull('archived_at')],
            'maximum_power_w' => ['required', 'integer', 'between:1,2000000'],
            'lifecycle_status' => ['required', Rule::in(['draft', 'active'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): Connector {
            $evse = Evse::query()->whereKey($data['evse_id'])->firstOrFail();
            // Serialize additions across all outlets: OCPP 1.6 numbers are station-wide.
            $station = ChargingStation::query()->lockForUpdate()->findOrFail($evse->charging_station_id);
            Gate::forUser($actor)->authorize('update', $station);
            $evse = Evse::query()->lockForUpdate()->whereKey($data['evse_id'])->firstOrFail();
            $duplicates = Connector::query()->where('connector_number', $data['connector_number']);
            if ($station->ocppVersion?->code === '2.0.1') {
                $duplicates->where('evse_id', $evse->getKey());
            } else {
                $duplicates->whereHas('evse', fn ($query) => $query->where('charging_station_id', $station->getKey()));
            }
            if ($duplicates->exists()) {
                throw ValidationException::withMessages(['connector_number' => 'That OCPP connector number is already in use. For OCPP 1.6, numbers must be unique across the station.']);
            }
            $connector = Connector::query()->create($data + [
                'tenant_id' => $station->tenant_id,
                'qr_identifier' => 'CP-'.Str::ulid(),
            ]);
            $this->audit($connector, 'connector');

            return $connector;
        });
    }

    private function audit(Evse|Connector $record, string $type): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'assets.'.$type.'.created', $type, (string) $record->getKey(), AuditResult::Succeeded,
            after: $record->attributesToArray(),
        ));
    }
}
