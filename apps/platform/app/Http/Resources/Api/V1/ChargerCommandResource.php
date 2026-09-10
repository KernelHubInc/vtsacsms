<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChargerCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'session_id' => (string) $this->resource->session_id,
            'type' => $this->resource->type->value,
            'state' => $this->resource->state->value,
            'correlation_id' => (string) $this->resource->correlation_id,
            'expires_at' => $this->resource->expires_at->utc()->toISOString(),
            'dispatched_at' => $this->resource->dispatched_at?->utc()->toISOString(),
            'acknowledged_at' => $this->resource->acknowledged_at?->utc()->toISOString(),
            'error_code' => $this->resource->error_code,
            'error_message' => $this->resource->error_message,
        ];
    }
}
