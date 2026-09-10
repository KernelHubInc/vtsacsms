<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Procurement\Domain\VendorInvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property VendorInvoiceStatus $status
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $matched_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $exported_at
 */
final class VendorInvoice extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'status' => VendorInvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'matched_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'exported_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<VendorInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
