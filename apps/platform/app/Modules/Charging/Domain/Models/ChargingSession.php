<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain\Models;

use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Charging\Domain\Models\ChargingSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $site_id
 * @property string|null $operator_id
 * @property string $charging_station_id
 * @property string $evse_id
 * @property string $connector_id
 * @property int $connector_maximum_power_w
 * @property string $charge_point_identity
 * @property string $protocol
 * @property string|null $protocol_transaction_id
 * @property ChargingSessionOrigin $origin
 * @property ChargingSessionState $state
 * @property AuthorizationStatus $authorization_status
 * @property string|null $authorization_token_id
 * @property string|null $tariff_version_id
 * @property array<string, mixed> $tariff_snapshot
 * @property string $tariff_snapshot_hash
 * @property string|null $currency
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $start_deadline_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $stopped_at
 * @property CarbonImmutable|null $last_protocol_event_at
 * @property int|null $meter_start_wh
 * @property int|null $meter_stop_wh
 * @property int $energy_wh
 * @property int $duration_seconds
 * @property int $parking_seconds
 * @property int $idle_seconds
 * @property int|null $estimated_cost_minor
 * @property int|null $final_cost_minor
 * @property string|null $finalization_outcome
 * @property string|null $failure_reason
 * @property string|null $cancellation_reason
 * @property list<string> $anomaly_flags
 * @property int $aggregate_version
 */
final class ChargingSession extends Model
{
    /** @use HasFactory<ChargingSessionFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'origin' => ChargingSessionOrigin::class,
            'state' => ChargingSessionState::class,
            'authorization_status' => AuthorizationStatus::class,
            'tariff_snapshot' => 'array',
            'anomaly_flags' => 'array',
            'requested_at' => 'immutable_datetime',
            'start_deadline_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'last_protocol_event_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ChargerCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(ChargerCommand::class, 'session_id');
    }

    /** @return HasMany<MeterReading, $this> */
    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class, 'session_id')->orderBy('sampled_at');
    }

    /** @return HasMany<ChargingSessionReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ChargingSessionReview::class, 'session_id');
    }

    /** @return HasMany<ChargeDetailRecord, $this> */
    public function chargeDetailRecords(): HasMany
    {
        return $this->hasMany(ChargeDetailRecord::class, 'session_id');
    }
}
