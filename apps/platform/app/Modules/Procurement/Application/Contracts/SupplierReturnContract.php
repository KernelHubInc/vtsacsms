<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\Contracts;

interface SupplierReturnContract
{
    public function register(
        string $purchaseOrderId,
        string $supplierId,
        string $inventoryReturnId,
        string $returnNumber,
        string $reason,
    ): void;
}
