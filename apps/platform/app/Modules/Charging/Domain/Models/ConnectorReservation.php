<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $session_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $released_at
 */
final class ConnectorReservation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'charging_connector_reservations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
