<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TariffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'name' => (string) $this->resource->name,
            'description' => $this->resource->description,
            'currency' => (string) $this->resource->currency,
            'status' => $this->resource->status->value,
            'versions' => TariffVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
