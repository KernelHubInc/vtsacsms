<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderLine extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'ordered_quantity_base' => 'integer',
            'received_quantity_base' => 'integer',
            'accepted_quantity_base' => 'integer',
            'returned_quantity_base' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
