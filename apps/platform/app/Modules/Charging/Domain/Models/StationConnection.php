<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $connection_id
 * @property bool $connected
 * @property CarbonImmutable $connected_at
 * @property CarbonImmutable $last_event_at
 * @property CarbonImmutable $last_seen_at
 */
final class StationConnection extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_station_connections';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'connected_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
