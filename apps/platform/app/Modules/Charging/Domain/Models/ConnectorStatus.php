<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $connector_id
 * @property ConnectorAvailability $status
 * @property CarbonImmutable $observed_at
 * @property int $stale_after_seconds
 */
final class ConnectorStatus extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_connector_statuses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ConnectorAvailability::class,
            'observed_at' => 'immutable_datetime',
            'stale_after_seconds' => 'integer',
        ];
    }
}
