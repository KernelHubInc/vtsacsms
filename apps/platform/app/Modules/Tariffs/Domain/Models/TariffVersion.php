<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Domain\TaxTreatment;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Tariffs\Domain\Models\TariffVersionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $tenant_id
 * @property string $tariff_id
 * @property int $version
 * @property TariffStatus $status
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property TaxTreatment $tax_treatment
 * @property int|null $tax_rate_basis_points
 * @property int|null $minimum_fee_minor
 * @property int|null $maximum_fee_minor
 * @property string|null $operator_id
 * @property string|null $site_id
 * @property string|null $connector_id
 * @property string $timezone
 * @property CarbonImmutable|null $published_at
 * @property string|null $published_by
 * @property Tariff $tariff
 * @property Collection<int, TariffComponent> $components
 * @property Collection<int, TariffDiscount> $discounts
 */
final class TariffVersion extends Model
{
    /** @use HasFactory<TariffVersionFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === TariffStatus::Published->value) {
                throw new LogicException('Published tariff versions are immutable. Create a new version.');
            }
        });
        self::deleting(function (self $version): void {
            if ($version->status === TariffStatus::Published) {
                throw new LogicException('Published tariff versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TariffStatus::class,
            'tax_treatment' => TaxTreatment::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tariff, $this> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /** @return HasMany<TariffComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(TariffComponent::class)->orderBy('priority')->orderBy('id');
    }

    /** @return HasMany<TariffDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(TariffDiscount::class)->orderBy('id');
    }
}
