<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryAuthorization;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;

final readonly class StockTransferPolicy
{
    public function __construct(
        private AuthorizationService $authorization,
        private InventoryAuthorization $inventoryAuthorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->allowsBoth($user, $transfer, PermissionKey::InventoryView);
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $this->allowsBoth($user, $transfer, PermissionKey::InventoryOperate);
    }

    private function allowsBoth(User $user, StockTransfer $transfer, PermissionKey $permission): bool
    {
        $source = Warehouse::query()->whereKey($transfer->source_warehouse_id)->first();
        $destination = Warehouse::query()->whereKey($transfer->destination_warehouse_id)->first();

        return $source !== null
            && $destination !== null
            && $this->inventoryAuthorization->allowsWarehouse($user, $permission, $source)
            && $this->inventoryAuthorization->allowsWarehouse($user, $permission, $destination);
    }
}
