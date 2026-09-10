<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Application\InventoryAuthorization;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;

final readonly class GoodsReceiptPolicy
{
    public function __construct(
        private AuthorizationService $authorization,
        private InventoryAuthorization $inventoryAuthorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        $warehouse = Warehouse::query()->whereKey($receipt->warehouse_id)->first();

        return $warehouse !== null
            && $this->inventoryAuthorization->allowsWarehouse($user, PermissionKey::InventoryView, $warehouse);
    }

    public function update(User $user, GoodsReceipt $receipt): bool
    {
        $warehouse = Warehouse::query()->whereKey($receipt->warehouse_id)->first();

        return $warehouse !== null
            && $this->inventoryAuthorization->allowsWarehouse($user, PermissionKey::InventoryOperate, $warehouse);
    }
}
