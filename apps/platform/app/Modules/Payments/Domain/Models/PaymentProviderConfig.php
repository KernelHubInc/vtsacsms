<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PaymentProviderConfig extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $configuration): void {
            if (PaymentIntent::query()->where('provider_config_id', $configuration->getKey())->exists()) {
                throw new \LogicException('A provider configuration referenced by a payment intent is frozen; create a new version.');
            }
        });
        self::deleting(function (self $configuration): void {
            if (PaymentIntent::query()->where('provider_config_id', $configuration->getKey())->exists()) {
                throw new \LogicException('A provider configuration referenced by a payment intent cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'is_active' => 'boolean'];
    }
}
