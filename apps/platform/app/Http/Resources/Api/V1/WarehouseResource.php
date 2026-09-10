<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WarehouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'site_id' => $this->resource->site_id,
            'code' => (string) $this->resource->code,
            'name' => (string) $this->resource->name,
            'type' => (string) $this->resource->type,
            'timezone' => (string) $this->resource->timezone,
            'is_active' => (bool) $this->resource->is_active,
        ];
    }
}
