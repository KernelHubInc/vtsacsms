<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $evidence_snapshot
 * @property CarbonImmutable $matched_at
 */
final class ThreeWayMatch extends TenantProcurementModel
{
    protected function casts(): array
    {
        return [
            'po_total_minor' => 'integer',
            'invoice_total_minor' => 'integer',
            'amount_variance_minor' => 'integer',
            'ordered_quantity_base' => 'integer',
            'accepted_quantity_base' => 'integer',
            'invoiced_quantity_base' => 'integer',
            'evidence_snapshot' => 'array',
            'matched_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ProcurementDiscrepancy, $this> */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(ProcurementDiscrepancy::class);
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new DomainException('Three-way match evidence is immutable.');
        });
        self::deleting(static function (): never {
            throw new DomainException('Three-way match evidence is immutable.');
        });
    }
}
