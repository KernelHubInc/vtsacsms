<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseRequestLine extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'quantity_base' => 'integer',
            'estimated_unit_minor' => 'integer',
            'estimated_total_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
