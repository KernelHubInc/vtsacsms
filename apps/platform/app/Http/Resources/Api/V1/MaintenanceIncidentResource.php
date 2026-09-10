<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaintenanceIncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'incident_number' => (string) $this->resource->incident_number,
            'source' => (string) $this->resource->source,
            'site_id' => (string) $this->resource->site_id,
            'asset_type' => (string) $this->resource->asset_type,
            'asset_id' => (string) $this->resource->asset_id,
            'fault_code' => (string) $this->resource->fault_code,
            'state' => $this->resource->state->value,
            'title' => (string) $this->resource->title,
            'details' => $this->resource->details,
            'occurrence_count' => (int) $this->resource->occurrence_count,
            'escalation_level' => (int) $this->resource->escalation_level,
            'first_observed_at' => $this->resource->first_observed_at->toIso8601String(),
            'last_observed_at' => $this->resource->last_observed_at->toIso8601String(),
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),
            'observations' => $this->whenLoaded('observations'),
        ];
    }
}
