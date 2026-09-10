<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Charging\Domain\Models\ChargerCommandFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $tenant_id
 * @property string $session_id
 * @property string $charging_station_id
 * @property string $connector_id
 * @property string $charge_point_identity
 * @property ChargerCommandType $type
 * @property string $ocpp_action
 * @property ChargerCommandState $state
 * @property string $idempotency_key
 * @property string $correlation_id
 * @property string $actor_id
 * @property string $reason_code
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $secret_payload
 * @property array<string, mixed> $expected_state
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $error_code
 * @property string|null $error_message
 */
final class ChargerCommand extends Model
{
    /** @use HasFactory<ChargerCommandFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'charging_commands';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ChargerCommandType::class,
            'state' => ChargerCommandState::class,
            'payload' => 'array',
            'secret_payload' => 'encrypted:array',
            'expected_state' => 'array',
            'expires_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ChargingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class, 'session_id');
    }
}
