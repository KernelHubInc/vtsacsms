<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

final class AdjustmentLine extends TenantInventoryModel
{
    protected $table = 'inventory_adjustment_lines';

    protected function casts(): array
    {
        return ['quantity_delta_base' => 'integer', 'unit_cost_minor' => 'integer'];
    }
}
