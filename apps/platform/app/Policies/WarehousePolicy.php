<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryAuthorization;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class WarehousePolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
        private InventoryAuthorization $inventoryAuthorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->sameTenant($warehouse)
            && $this->inventoryAuthorization->allowsWarehouse($user, PermissionKey::InventoryView, $warehouse);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->allows($user, PermissionKey::InventoryOperate);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->sameTenant($warehouse)
            && $this->inventoryAuthorization->allowsWarehouse($user, PermissionKey::InventoryOperate, $warehouse);
    }

    public function adjust(User $user, Warehouse $warehouse): bool
    {
        return $this->sameTenant($warehouse)
            && $this->inventoryAuthorization->allowsWarehouse($user, PermissionKey::InventoryAdjust, $warehouse);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    public function restore(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    public function forceDelete(User $user, Warehouse $warehouse): bool
    {
        return false;
    }

    private function sameTenant(Warehouse $warehouse): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $warehouse->tenant_id);
    }
}
