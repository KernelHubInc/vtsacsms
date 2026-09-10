<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $system_quantity_base
 * @property int|null $counted_quantity_base
 * @property int|null $variance_quantity_base
 * @property CarbonImmutable|null $counted_at
 */
final class CountLine extends TenantInventoryModel
{
    protected $table = 'inventory_count_lines';

    protected function casts(): array
    {
        return [
            'system_quantity_base' => 'integer',
            'counted_quantity_base' => 'integer',
            'variance_quantity_base' => 'integer',
            'counted_at' => 'immutable_datetime',
        ];
    }
}
