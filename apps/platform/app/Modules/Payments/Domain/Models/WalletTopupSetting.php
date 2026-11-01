<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property list<array{amount_minor:int,enabled:bool}>|null $presets */
final class WalletTopupSetting extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['minimum_minor', 'maximum_minor', 'presets', 'allow_custom_amount'];

    protected function casts(): array
    {
        return ['minimum_minor' => 'integer', 'maximum_minor' => 'integer', 'presets' => 'array', 'allow_custom_amount' => 'boolean'];
    }
}
