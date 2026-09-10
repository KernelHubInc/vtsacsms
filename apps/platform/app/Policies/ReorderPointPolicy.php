<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryAuthorization;
use App\Modules\Inventory\Domain\Models\ReorderPoint;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class ReorderPointPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
        private InventoryAuthorization $inventory,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, ReorderPoint $point): bool
    {
        return $this->allows($user, $point, PermissionKey::InventoryView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->holdsAnywhere($user, PermissionKey::InventoryOperate);
    }

    public function update(User $user, ReorderPoint $point): bool
    {
        return $this->allows($user, $point, PermissionKey::InventoryOperate);
    }

    public function delete(User $user, ReorderPoint $point): bool
    {
        return false;
    }

    private function allows(User $user, ReorderPoint $point, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();
        if ($context === null || ! hash_equals($context->tenantId, (string) $point->tenant_id)) {
            return false;
        }

        $warehouse = Warehouse::query()->whereKey($point->warehouse_id)->first();

        return $warehouse !== null && $this->inventory->allowsWarehouse($user, $permission, $warehouse);
    }
}
