<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property array<string, mixed>|null $commercial_terms
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $valid_until
 */
final class SupplierQuotation extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'commercial_terms' => 'array',
            'submitted_at' => 'immutable_datetime',
            'valid_until' => 'immutable_date',
        ];
    }

    /** @return HasMany<SupplierQuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuotationLine::class);
    }
}
