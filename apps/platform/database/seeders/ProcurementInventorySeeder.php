<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\ApprovalRule;
use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Procurement\Domain\Models\Department;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcurementInventorySeeder extends Seeder
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
                $department = Department::query()->firstOrCreate(
                    ['code' => 'OPERATIONS'],
                    ['name' => 'Operations', 'is_active' => true],
                );
                $costCenter = CostCenter::query()->firstOrCreate(
                    ['code' => 'EV-NETWORK'],
                    ['name' => 'EV Network', 'is_active' => true],
                );
                Supplier::query()->firstOrCreate(
                    ['code' => 'LOCAL-DEMO'],
                    [
                        'name' => 'Local development supplier',
                        'email' => 'supplier@example.test',
                        'status' => 'active',
                    ],
                );
                $currency = mb_strtoupper(trim((string) config('app.seed_demo_currency', 'PHP')));
                if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                    foreach (['purchase_request', 'purchase_order'] as $documentType) {
                        ApprovalRule::query()->firstOrCreate(
                            [
                                'document_type' => $documentType,
                                'department_id' => $documentType === 'purchase_request' ? $department->getKey() : null,
                                'cost_center_id' => $documentType === 'purchase_request' ? $costCenter->getKey() : null,
                                'currency' => $currency,
                                'minimum_amount_minor' => 0,
                                'sequence' => 1,
                            ],
                            [
                                'maximum_amount_minor' => null,
                                'approver_role_key' => 'procurement-approver',
                                'is_active' => true,
                            ],
                        );
                    }
                }

                UnitOfMeasure::query()->firstOrCreate(
                    ['code' => 'EA'],
                    ['name' => 'Each', 'dimension' => 'count', 'base_multiplier' => 1, 'is_active' => true],
                );
                ItemCategory::query()->firstOrCreate(
                    ['code' => 'SPARES'],
                    ['name' => 'Charging spares'],
                );
                $warehouse = Warehouse::query()->firstOrCreate(
                    ['code' => 'CENTRAL'],
                    [
                        'name' => 'Central warehouse',
                        'type' => 'warehouse',
                        'timezone' => 'Asia/Manila',
                        'is_active' => true,
                    ],
                );
                foreach ([
                    ['code' => 'AVAILABLE', 'name' => 'Available stock', 'custody_type' => 'available', 'bin' => 'A-001'],
                    ['code' => 'QUARANTINE', 'name' => 'Inspection quarantine', 'custody_type' => 'quarantine', 'bin' => 'Q-001'],
                    ['code' => 'RETURNS', 'name' => 'Returns holding', 'custody_type' => 'returns', 'bin' => 'R-001'],
                ] as $locationData) {
                    $location = StockLocation::query()->firstOrCreate(
                        ['warehouse_id' => $warehouse->getKey(), 'code' => $locationData['code']],
                        [
                            'name' => $locationData['name'],
                            'custody_type' => $locationData['custody_type'],
                            'is_active' => true,
                        ],
                    );
                    InventoryBin::query()->firstOrCreate(
                        ['warehouse_id' => $warehouse->getKey(), 'code' => $locationData['bin']],
                        [
                            'stock_location_id' => $location->getKey(),
                            'name' => $locationData['name'],
                            'is_active' => true,
                        ],
                    );
                }
                $transitWarehouse = Warehouse::query()->firstOrCreate(
                    ['code' => 'IN-TRANSIT'],
                    [
                        'name' => 'In-transit custody',
                        'type' => 'in_transit',
                        'timezone' => 'UTC',
                        'is_active' => true,
                    ],
                );
                $transitLocation = StockLocation::query()->firstOrCreate(
                    ['warehouse_id' => $transitWarehouse->getKey(), 'code' => 'IN-TRANSIT'],
                    ['name' => 'In-transit custody', 'custody_type' => 'in_transit', 'is_active' => true],
                );
                InventoryBin::query()->firstOrCreate(
                    ['warehouse_id' => $transitWarehouse->getKey(), 'code' => 'TRANSIT'],
                    [
                        'stock_location_id' => $transitLocation->getKey(),
                        'name' => 'Transfer custody',
                        'is_active' => true,
                    ],
                );

                $this->seedRole($tenant, 'procurement-requester', 'Procurement requester', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::ProcurementView,
                    PermissionKey::ProcurementManage,
                ]);
                $this->seedRole($tenant, 'procurement-approver', 'Procurement approver', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::ProcurementView,
                    PermissionKey::ProcurementApprove,
                ]);
                $this->seedRole($tenant, 'inventory-operator', 'Inventory operator', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::InventoryView,
                    PermissionKey::InventoryOperate,
                ]);
                $this->seedRole($tenant, 'inventory-controller', 'Inventory controller', [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::InventoryView,
                    PermissionKey::InventoryOperate,
                    PermissionKey::InventoryAdjust,
                    PermissionKey::ReportingView,
                    PermissionKey::ReportingExport,
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
