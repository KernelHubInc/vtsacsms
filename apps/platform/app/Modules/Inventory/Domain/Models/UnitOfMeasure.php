<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Database\Factories\Modules\Inventory\Domain\Models\UnitOfMeasureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class UnitOfMeasure extends TenantInventoryModel
{
    /** @use HasFactory<UnitOfMeasureFactory> */
    use HasFactory;

    protected $table = 'inventory_units_of_measure';

    protected function casts(): array
    {
        return ['base_multiplier' => 'integer', 'is_active' => 'boolean'];
    }
}
