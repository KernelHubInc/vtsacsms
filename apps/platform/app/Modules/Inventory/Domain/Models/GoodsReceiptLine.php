<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GoodsReceiptLine extends TenantInventoryModel
{
    protected $table = 'inventory_goods_receipt_lines';

    protected function casts(): array
    {
        return [
            'received_quantity_base' => 'integer',
            'accepted_quantity_base' => 'integer',
            'rejected_quantity_base' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
