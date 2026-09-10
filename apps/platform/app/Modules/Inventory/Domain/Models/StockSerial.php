<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Carbon\CarbonImmutable;

/** @property CarbonImmutable|null $handed_to_assets_at */
final class StockSerial extends TenantInventoryModel
{
    protected $table = 'inventory_serials';

    protected function casts(): array
    {
        return ['handed_to_assets_at' => 'immutable_datetime'];
    }
}
