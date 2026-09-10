<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $session_id
 * @property string $event_id
 * @property CarbonImmutable $sampled_at
 * @property CarbonImmutable $received_at
 * @property string $measurand
 * @property string $unit
 * @property int $value
 * @property bool $is_out_of_order
 * @property bool $is_meter_reset
 */
final class MeterReading extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_meter_readings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'is_out_of_order' => 'boolean',
            'is_meter_reset' => 'boolean',
        ];
    }
}
