<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\DiscountKind;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Tariffs\Domain\Models\TariffDiscountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $tenant_id
 * @property string $tariff_version_id
 * @property string $name
 * @property string|null $code
 * @property DiscountKind $kind
 * @property int $value
 * @property bool $is_automatic
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 */
final class TariffDiscount extends Model
{
    /** @use HasFactory<TariffDiscountFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        $assertDraft = static function (self $discount): void {
            if ($discount->version()->withoutGlobalScopes()->first()?->status === TariffStatus::Published) {
                throw new LogicException('Published tariff discounts are immutable. Create a new tariff version.');
            }
        };

        self::saving($assertDraft);
        self::deleting($assertDraft);
    }

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'is_automatic' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TariffVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TariffVersion::class, 'tariff_version_id');
    }
}
