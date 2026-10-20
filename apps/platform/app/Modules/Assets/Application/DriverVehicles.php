<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Domain\Models\DriverGarage;
use App\Modules\Assets\Domain\Models\DriverVehicle;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public application contract for a tenant-scoped driver's garage. */
final readonly class DriverVehicles
{
    public function __construct(private AuditRecorder $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(string $subjectId, ?string $accountId = null): array
    {
        $owner = $this->owner($subjectId, $accountId);
        $garage = DriverGarage::query()->where($owner)->first();

        $vehicles = DriverVehicle::query()->where('garage_id', $garage?->getKey());
        if ($accountId !== null && $owner['subject_id'] === null && ! $this->manages($subjectId, $accountId)) {
            $vehicles->whereIn('id', DB::table('driver_vehicle_assignments')->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)
                ->where('subject_id', $subjectId)->select('vehicle_id'));
        }

        return $garage === null ? [] : array_values($vehicles
            ->orderByDesc('is_default')->orderBy('id')->get()->map(fn (DriverVehicle $vehicle): array => $this->present($vehicle))->values()->all());
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(string $subjectId, string $id, array $data, ?string $accountId = null): array
    {
        try {
            return DB::transaction(function () use ($subjectId, $id, $data, $accountId): array {
                $owner = $this->owner($subjectId, $accountId, true);
                $garage = DriverGarage::query()->firstOrCreate($owner);
                // Serialize default selection even when this driver has no vehicles yet.
                DriverGarage::query()->whereKey($garage->getKey())->lockForUpdate()->firstOrFail();
                $vehicle = DriverVehicle::query()->whereKey($id)->first();
                abort_if($vehicle !== null && $vehicle->garage_id !== $garage->getKey(), 404);
                $default = ($data['is_default'] ?? false) || ! DriverVehicle::query()->where('garage_id', $garage->getKey())->exists();
                if ($default) {
                    DriverVehicle::query()->where('garage_id', $garage->getKey())->where('is_default', true)->update(['is_default' => false]);
                } elseif ($vehicle?->is_default) {
                    $default = true;
                }
                $vehicle ??= new DriverVehicle(['id' => $id, 'garage_id' => $garage->getKey()]);
                $vehicle->fill([
                    'nickname' => $data['nickname'], 'plate_number' => ($data['plate_pending'] ?? false) ? null : ($data['plate_number'] ?? null),
                    'plate_pending' => $data['plate_pending'] ?? false, 'manufacturer' => $data['manufacturer'] ?? '',
                    'model' => $data['model'] ?? '', 'variant' => $data['variant'] ?? null,
                    'connector_standards' => array_values(array_unique($data['connector_standards'] ?? [])), 'is_default' => $default,
                ])->save();
                $this->audit->record(new AuditEntry('assets.driver_vehicle.saved', 'driver_vehicle', $id, AuditResult::Succeeded));

                return $this->present($vehicle);
            });
        } catch (UniqueConstraintViolationException) {
            // A caller-chosen ID may already belong to another tenant. Never disclose its owner.
            abort(409, 'Vehicle could not be saved. Refresh and try again.');
        }
    }

    public function remove(string $subjectId, string $id, ?string $accountId = null): void
    {
        DB::transaction(function () use ($subjectId, $id, $accountId): void {
            $garage = DriverGarage::query()->where($this->owner($subjectId, $accountId, true))->lockForUpdate()->firstOrFail();
            $vehicle = DriverVehicle::query()->where('garage_id', $garage->getKey())->findOrFail($id);
            $wasDefault = $vehicle->is_default;
            $vehicle->delete();
            if ($wasDefault) {
                DriverVehicle::query()->where('garage_id', $garage->getKey())->orderBy('id')->first()?->update(['is_default' => true]);
            }
            $this->audit->record(new AuditEntry('assets.driver_vehicle.removed', 'driver_vehicle', $id, AuditResult::Succeeded));
        });
    }

    /** @return array{subject_id: ?string, charging_account_id: ?string} */
    private function owner(string $subjectId, ?string $accountId, bool $manage = false): array
    {
        if ($accountId === null) {
            return ['subject_id' => $subjectId, 'charging_account_id' => null];
        }
        $account = app(ChargingAccounts::class)->require(User::query()->where('public_id', $subjectId)->firstOrFail(), $accountId,
            $manage ? PermissionKey::ChargingVehiclesManage : PermissionKey::ChargingAccountView, $manage);

        return $account->charging_account_type === 'personal'
            ? ['subject_id' => $subjectId, 'charging_account_id' => null]
            : ['subject_id' => null, 'charging_account_id' => $accountId];
    }

    private function manages(string $subject, string $account): bool
    {
        return app(ChargingAccounts::class)->can(User::query()->where('public_id', $subject)->firstOrFail(), $account, PermissionKey::ChargingVehiclesManage);
    }

    /** @return array<string, mixed> */
    public function chargeVehicle(string $subject, ?string $account, string $vehicleId): array
    {
        $vehicle = collect($this->list($subject, $account))->firstWhere('id', $vehicleId);
        abort_if($vehicle === null, 404);

        return ['id' => $vehicle['id'], 'nickname' => $vehicle['nickname']];
    }

    /** @return list<string> */
    public function assignments(string $subject, string $account, string $vehicle): array
    {
        $owner = $this->owner($subject, $account, true);
        $garage = DriverGarage::query()->where($owner)->firstOrFail();
        DriverVehicle::query()->where('garage_id', $garage->getKey())->findOrFail($vehicle);

        return array_values(DB::table('driver_vehicle_assignments')->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)
            ->where('vehicle_id', $vehicle)->pluck('subject_id')->map(fn ($id): string => (string) $id)->all());
    }

    public function assign(string $subject, string $account, string $vehicle, string $driver, bool $enabled): void
    {
        DB::transaction(function () use ($subject, $account, $vehicle, $driver, $enabled): void {
            $this->assignments($subject, $account, $vehicle);
            app(ChargingAccounts::class)->require(User::query()->where('public_id', $driver)->firstOrFail(), $account, PermissionKey::ChargingAccountCharge);
            $key = ['tenant_id' => app(CurrentTenant::class)->get()->tenantId, 'vehicle_id' => $vehicle, 'subject_id' => $driver];
            if ($enabled) {
                DB::table('driver_vehicle_assignments')->insertOrIgnore($key + ['id' => (string) Str::ulid(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            } else {
                DB::table('driver_vehicle_assignments')->where($key)->delete();
            }
            $this->audit->record(new AuditEntry('assets.driver_vehicle.assignment_changed', 'driver_vehicle', $vehicle, AuditResult::Succeeded,
                changes: ['subject_id' => $driver, 'assigned' => $enabled]));
        });
    }

    public function eraseForSubject(string $subjectId): void
    {
        DB::transaction(function () use ($subjectId): void {
            $garage = DriverGarage::query()->where('subject_id', $subjectId)->lockForUpdate()->first();
            if ($garage === null) {
                return;
            }
            DriverVehicle::query()->where('garage_id', $garage->getKey())->delete();
            $id = (string) $garage->getKey();
            $garage->delete();
            $this->audit->record(new AuditEntry('assets.driver_garage.erased', 'driver_garage', $id, AuditResult::Succeeded));
        });
    }

    /** @return array<string, mixed> */
    private function present(DriverVehicle $vehicle): array
    {
        return $vehicle->only(['id', 'nickname', 'plate_number', 'plate_pending', 'manufacturer', 'model', 'variant', 'connector_standards', 'is_default']);
    }
}
