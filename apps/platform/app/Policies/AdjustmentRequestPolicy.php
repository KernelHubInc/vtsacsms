<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryAuthorization;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;

final readonly class AdjustmentRequestPolicy
{
    public function __construct(
        private AuthorizationService $authorization,
        private InventoryAuthorization $inventoryAuthorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, AdjustmentRequest $request): bool
    {
        return $this->allows($user, $request, PermissionKey::InventoryView);
    }

    public function update(User $user, AdjustmentRequest $request): bool
    {
        return $this->allows($user, $request, PermissionKey::InventoryAdjust);
    }

    private function allows(User $user, AdjustmentRequest $request, PermissionKey $permission): bool
    {
        $warehouse = Warehouse::query()->whereKey($request->warehouse_id)->first();

        return $warehouse !== null
            && $this->inventoryAuthorization->allowsWarehouse($user, $permission, $warehouse);
    }
}
