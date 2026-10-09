<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Domain\Models\DriverGarage;
use App\Modules\Assets\Domain\Models\DriverVehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Public application contract for a tenant-scoped driver's garage. */
final readonly class DriverVehicles
{
    public function __construct(private AuditRecorder $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(string $subjectId): array
    {
        $garage = DriverGarage::query()->where('subject_id', $subjectId)->first();

        return $garage === null ? [] : array_values(DriverVehicle::query()->where('garage_id', $garage->getKey())
            ->orderByDesc('is_default')->orderBy('id')->get()->map(fn (DriverVehicle $vehicle): array => $this->present($vehicle))->values()->all());
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(string $subjectId, string $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($subjectId, $id, $data): array {
                $garage = DriverGarage::query()->firstOrCreate(['subject_id' => $subjectId]);
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

    public function remove(string $subjectId, string $id): void
    {
        DB::transaction(function () use ($subjectId, $id): void {
            $garage = DriverGarage::query()->where('subject_id', $subjectId)->lockForUpdate()->firstOrFail();
            $vehicle = DriverVehicle::query()->where('garage_id', $garage->getKey())->findOrFail($id);
            $wasDefault = $vehicle->is_default;
            $vehicle->delete();
            if ($wasDefault) {
                DriverVehicle::query()->where('garage_id', $garage->getKey())->orderBy('id')->first()?->update(['is_default' => true]);
            }
            $this->audit->record(new AuditEntry('assets.driver_vehicle.removed', 'driver_vehicle', $id, AuditResult::Succeeded));
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
