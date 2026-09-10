<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Maintenance\Domain\Models\MaintenanceCode;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MaintenanceSeeder extends Seeder
{
    public function run(CurrentTenant $currentTenant): void
    {
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($currentTenant): void {
            $currentTenant->run(new TenantContext(
                (string) $tenant->getKey(),
                ActorType::Platform,
                null,
                (string) Str::ulid(),
            ), function () use ($tenant): void {
                foreach ([
                    ['code' => 'CRITICAL', 'name' => 'Critical', 'rank' => 10, 'color' => 'danger'],
                    ['code' => 'HIGH', 'name' => 'High', 'rank' => 20, 'color' => 'warning'],
                    ['code' => 'NORMAL', 'name' => 'Normal', 'rank' => 30, 'color' => 'info'],
                    ['code' => 'LOW', 'name' => 'Low', 'rank' => 40, 'color' => 'gray'],
                ] as $priority) {
                    MaintenancePriority::query()->firstOrCreate(
                        ['code' => $priority['code']],
                        [...$priority, 'is_active' => true],
                    );
                }
                foreach ([
                    ['type' => 'failure', 'code' => 'UNCLASSIFIED', 'name' => 'Unclassified failure'],
                    ['type' => 'root_cause', 'code' => 'UNDETERMINED', 'name' => 'Root cause undetermined'],
                    ['type' => 'resolution', 'code' => 'RESTORED_AND_VALIDATED', 'name' => 'Restored and validated'],
                ] as $code) {
                    MaintenanceCode::query()->firstOrCreate(
                        ['type' => $code['type'], 'code' => $code['code']],
                        ['name' => $code['name'], 'is_active' => true],
                    );
                }

                $this->seedRole($tenant, 'maintenance-dispatcher', 'Maintenance dispatcher', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::MaintenanceView,
                    PermissionKey::MaintenanceDispatch,
                    PermissionKey::ReportingView,
                ]);
                $this->seedRole($tenant, 'maintenance-technician', 'Maintenance technician', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::MaintenanceView,
                    PermissionKey::MaintenancePerform,
                    PermissionKey::InventoryView,
                    PermissionKey::InventoryOperate,
                ]);
                $this->seedRole($tenant, 'maintenance-verifier', 'Maintenance verifier', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::MaintenanceView,
                    PermissionKey::MaintenanceVerify,
                    PermissionKey::ReportingView,
                ]);
            });
        });
    }

    /** @param list<PermissionKey> $permissions */
    private function seedRole(Tenant $tenant, string $key, string $name, array $permissions): void
    {
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => $key],
            ['name' => $name, 'is_system' => true],
        );
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                [
                    'tenant_id' => $tenant->getKey(),
                    'role_id' => $role->getKey(),
                    'permission_key' => $permission->value,
                ],
                ['created_at' => now('UTC')],
            );
        }
    }
}
