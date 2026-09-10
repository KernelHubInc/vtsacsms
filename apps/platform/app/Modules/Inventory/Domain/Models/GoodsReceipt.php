<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $inspected_at
 * @property CarbonImmutable|null $posted_at
 */
final class GoodsReceipt extends TenantInventoryModel
{
    protected $table = 'inventory_goods_receipts';

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'inspected_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
