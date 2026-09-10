<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\TariffDimension;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Database\Factories\Modules\Tariffs\Domain\Models\TariffComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $tenant_id
 * @property string $tariff_version_id
 * @property TariffDimension $dimension
 * @property int $price_minor
 * @property int $unit_quantity
 * @property int $day_of_week_mask
 * @property string|null $starts_at_local
 * @property string|null $ends_at_local
 * @property int $priority
 */
final class TariffComponent extends Model
{
    /** @use HasFactory<TariffComponentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        $assertDraft = static function (self $component): void {
            if ($component->version()->withoutGlobalScopes()->first()?->status === TariffStatus::Published) {
                throw new LogicException('Published tariff components are immutable. Create a new tariff version.');
            }
        };

        self::saving($assertDraft);
        self::deleting($assertDraft);
    }

    protected function casts(): array
    {
        return ['dimension' => TariffDimension::class];
    }

    /** @return BelongsTo<TariffVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TariffVersion::class, 'tariff_version_id');
    }
}
