<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $subject_id
 * @property string|null $charging_account_id
 * @property string|null $vehicle_id
 * @property string|null $payer_account_id
 * @property string|null $prepaid_account_id
 * @property string $connector_id
 * @property string $tariff_version_id
 * @property int $budget_minor
 * @property array<string, mixed> $disclosure
 * @property CarbonImmutable $expires_at
 */
final class ChargingQuote extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_quotes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['disclosure' => 'array', 'expires_at' => 'immutable_datetime', 'budget_minor' => 'integer'];
    }
}
