<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChargeDetailRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'version' => (int) $this->resource->version,
            'state' => $this->resource->state->value,
            'currency' => $this->resource->currency,
            'energy_wh' => (int) $this->resource->energy_wh,
            'duration_seconds' => (int) $this->resource->duration_seconds,
            'subtotal_minor' => $this->resource->subtotal_minor,
            'discount_minor' => $this->resource->discount_minor,
            'tax_minor' => $this->resource->tax_minor,
            'total_minor' => $this->resource->total_minor,
            'snapshot_hash' => $this->resource->snapshot_hash,
            'finalized_at' => $this->resource->finalized_at?->utc()->toISOString(),
        ];
    }
}
