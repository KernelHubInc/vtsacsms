<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChargingSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeFinance = ! app()->environment('staging') || ($user instanceof User && app(AuthorizationService::class)->allows(
            $user, PermissionKey::PaymentView, new ResourceScope(ScopeType::Site, (string) $this->resource->site_id),
        ));

        return [
            'id' => (string) $this->resource->getKey(),
            'site_id' => (string) $this->resource->site_id,
            'charging_station_id' => (string) $this->resource->charging_station_id,
            'evse_id' => (string) $this->resource->evse_id,
            'connector_id' => (string) $this->resource->connector_id,
            'origin' => $this->resource->origin->value,
            'state' => $this->resource->state->value,
            'authorization_status' => $this->resource->authorization_status->value,
            'protocol' => (string) $this->resource->protocol,
            'protocol_transaction_id' => $this->resource->protocol_transaction_id,
            'requested_at' => $this->resource->requested_at->utc()->toISOString(),
            'started_at' => $this->resource->started_at?->utc()->toISOString(),
            'stopped_at' => $this->resource->stopped_at?->utc()->toISOString(),
            'energy_wh' => (int) $this->resource->energy_wh,
            'duration_seconds' => (int) $this->resource->duration_seconds,
            'parking_seconds' => (int) $this->resource->parking_seconds,
            'idle_seconds' => (int) $this->resource->idle_seconds,
            'estimated_cost_minor' => $canSeeFinance ? $this->resource->estimated_cost_minor : null,
            'final_cost_minor' => $canSeeFinance ? $this->resource->final_cost_minor : null,
            'currency' => $this->resource->currency,
            'tariff_snapshot' => $canSeeFinance ? $this->resource->tariff_snapshot : (object) [],
            'tariff_snapshot_hash' => (string) $this->resource->tariff_snapshot_hash,
            'anomaly_flags' => $this->resource->anomaly_flags,
            'finalization_outcome' => $this->resource->finalization_outcome,
            'failure_reason' => $this->resource->failure_reason,
            'cancellation_reason' => $this->resource->cancellation_reason,
            'aggregate_version' => (int) $this->resource->aggregate_version,
            'commands' => ChargerCommandResource::collection($this->whenLoaded('commands')),
            'charge_detail_records' => $this->when($canSeeFinance, fn () => ChargeDetailRecordResource::collection($this->whenLoaded('chargeDetailRecords'))),
        ];
    }
}
