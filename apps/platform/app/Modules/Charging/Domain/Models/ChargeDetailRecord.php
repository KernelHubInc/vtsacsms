<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $tenant_id
 * @property string $session_id
 * @property int $version
 * @property ChargeDetailRecordState $state
 * @property array<string, mixed>|null $snapshot
 * @property string|null $snapshot_hash
 * @property string|null $currency
 * @property int $energy_wh
 * @property int $duration_seconds
 * @property int|null $subtotal_minor
 * @property int|null $discount_minor
 * @property int|null $tax_minor
 * @property int|null $total_minor
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $finalized_at
 */
final class ChargeDetailRecord extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $record): void {
            if ($record->getRawOriginal('state') !== ChargeDetailRecordState::Finalized->value) {
                return;
            }

            $changedEvidence = array_diff(array_keys($record->getDirty()), ['state']);
            if ($record->state !== ChargeDetailRecordState::Superseded || $changedEvidence !== []) {
                throw new LogicException('Finalized charge detail records are immutable and may only be superseded.');
            }
        });
        self::deleting(function (self $record): void {
            if ($record->state === ChargeDetailRecordState::Finalized) {
                throw new LogicException('Finalized charge detail records cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state' => ChargeDetailRecordState::class,
            'snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
