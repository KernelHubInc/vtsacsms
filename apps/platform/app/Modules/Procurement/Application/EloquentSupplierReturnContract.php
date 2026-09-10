<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Procurement\Application\Contracts\SupplierReturnContract;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\SupplierReturn;
use DomainException;

final class EloquentSupplierReturnContract implements SupplierReturnContract
{
    public function register(
        string $purchaseOrderId,
        string $supplierId,
        string $inventoryReturnId,
        string $returnNumber,
        string $reason,
    ): void {
        $order = PurchaseOrder::query()->whereKey($purchaseOrderId)->firstOrFail();
        if ($order->supplier_id !== $supplierId) {
            throw new DomainException('The supplier return does not match the purchase order.');
        }
        SupplierReturn::query()->firstOrCreate(
            ['inventory_return_id' => $inventoryReturnId],
            [
                'purchase_order_id' => $purchaseOrderId,
                'supplier_id' => $supplierId,
                'return_number' => $returnNumber,
                'status' => 'shipped',
                'reason' => $reason,
                'shipped_at' => now('UTC'),
            ],
        );
    }
}
