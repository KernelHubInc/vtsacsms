<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $billing_profile_id
 * @property string $provider_config_id
 * @property string|null $payment_method_id
 * @property string $billable_type
 * @property string $billable_id
 * @property PaymentIntentState $state
 * @property string $currency
 * @property int $amount_requested_minor
 * @property int $amount_authorized_minor
 * @property int $amount_captured_minor
 * @property int $amount_refunded_minor
 * @property string $idempotency_key
 * @property string|null $provider_intent_reference
 * @property bool $preauthorization_required
 * @property int $aggregate_version
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $captured_at
 */
final class PaymentIntent extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => PaymentIntentState::class,
            'preauthorization_required' => 'boolean',
            'authorized_at' => 'immutable_datetime', 'captured_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
