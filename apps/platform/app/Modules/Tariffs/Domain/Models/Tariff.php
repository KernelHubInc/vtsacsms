<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain\Models;

use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Tariffs\Domain\Models\TariffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $currency
 * @property TariffStatus $status
 * @property string|null $created_by
 * @property CarbonImmutable|null $archived_at
 */
final class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TariffStatus::class, 'archived_at' => 'immutable_datetime'];
    }

    /** @return HasMany<TariffVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TariffVersion::class);
    }
}
