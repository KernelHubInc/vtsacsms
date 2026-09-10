<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

final class InventoryReturnLine extends TenantInventoryModel
{
    protected $table = 'inventory_return_lines';

    protected function casts(): array
    {
        return ['quantity_base' => 'integer', 'unit_cost_minor' => 'integer'];
    }
}
