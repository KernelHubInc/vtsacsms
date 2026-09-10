<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('issued_at') === null) {
                return;
            }
            $allowed = ['amount_paid_minor', 'status', 'updated_at'];
            if (array_diff(array_keys($invoice->getDirty()), $allowed) !== []) {
                throw new LogicException('Issued invoice content is immutable.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Invoices cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['legal_review_required' => 'boolean', 'issued_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
