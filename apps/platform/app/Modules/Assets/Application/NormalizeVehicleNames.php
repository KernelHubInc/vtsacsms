<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Domain\Models\DriverVehicle;
use App\Modules\Assets\Domain\VehicleText;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class NormalizeVehicleNames
{
    public function __construct(private AuditRecorder $audit, private CurrentTenant $tenant) {}

    public function run(bool $apply): int
    {
        $count = 0;
        foreach (DriverVehicle::query()->select(['id', 'manufacturer', 'model'])->lazyById(250) as $vehicle) {
            $brand = VehicleText::normalize($vehicle->manufacturer);
            $model = VehicleText::normalize($vehicle->model);
            if ($brand === $vehicle->manufacturer && $model === $vehicle->model) {
                continue;
            }
            // Do not overwrite an edit made while this resumable backfill is running.
            $count += $apply ? DriverVehicle::query()->whereKey($vehicle->getKey())
                ->where('manufacturer', $vehicle->manufacturer)->where('model', $vehicle->model)
                ->update(['manufacturer' => $brand, 'model' => $model]) : 1;
        }
        if ($apply) {
            $this->audit->record(new AuditEntry(
                action: 'assets.vehicle_names.normalized', targetType: 'tenant',
                targetId: $this->tenant->get()->tenantId, result: AuditResult::Succeeded,
                metadata: ['updated_count' => $count],
            ));
        }

        return $count;
    }
}
