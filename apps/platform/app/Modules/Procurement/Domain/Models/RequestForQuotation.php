<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $closes_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $closed_at
 */
final class RequestForQuotation extends TenantProcurementModel
{
    protected $table = 'procurement_rfqs';

    protected function casts(): array
    {
        return [
            'closes_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** @return HasMany<SupplierQuotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class, 'rfq_id');
    }
}
