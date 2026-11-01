<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property list<int> $presets */
final class ChargingBudgetSetting extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['minimum_minor', 'default_minor', 'maximum_minor', 'presets', 'allow_custom_amount'];

    protected function casts(): array
    {
        return ['minimum_minor' => 'integer', 'default_minor' => 'integer', 'maximum_minor' => 'integer', 'presets' => 'array', 'allow_custom_amount' => 'boolean'];
    }
}
