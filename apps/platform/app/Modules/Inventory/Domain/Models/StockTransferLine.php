<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

final class StockTransferLine extends TenantInventoryModel
{
    protected $table = 'inventory_transfer_lines';

    protected function casts(): array
    {
        return [
            'requested_quantity_base' => 'integer',
            'dispatched_quantity_base' => 'integer',
            'received_quantity_base' => 'integer',
        ];
    }
}
