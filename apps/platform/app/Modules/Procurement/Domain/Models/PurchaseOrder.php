<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PurchaseOrderStatus $status
 * @property int $revision
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $closed_at
 */
final class PurchaseOrder extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'revision' => 'integer',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'approved_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
