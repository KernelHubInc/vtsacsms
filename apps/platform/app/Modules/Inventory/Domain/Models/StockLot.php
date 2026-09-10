<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

final class StockLot extends TenantInventoryModel
{
    protected $table = 'inventory_lots';

    protected function casts(): array
    {
        return ['manufactured_on' => 'immutable_date', 'expires_on' => 'immutable_date'];
    }
}
