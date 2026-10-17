<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $subject_id
 * @property string|null $charging_account_id
 * @property string|null $vehicle_id
 * @property string|null $payer_account_id
 * @property string|null $prepaid_account_id
 * @property string $session_id
 * @property string $quote_id
 * @property int $budget_minor
 * @property string $payment_state
 * @property CarbonImmutable|null $settled_at
 * @property-read ChargingQuote $quote
 */
final class CustomerChargingSession extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_customer_sessions';

    protected $guarded = [];

    /** @return BelongsTo<ChargingQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(ChargingQuote::class, 'quote_id');
    }

    protected function casts(): array
    {
        return ['budget_minor' => 'integer', 'settled_at' => 'immutable_datetime'];
    }
}
