<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'code' => $this->resource->code,
            'operator_organization_id' => $this->resource->operator_organization_id,
            'site_host_organization_id' => $this->resource->site_host_organization_id,
            'active' => (bool) $this->resource->is_active,
            'lifecycle_status' => $this->resource->lifecycle_status->value,
            'public' => (bool) $this->resource->is_public,
            'timezone' => $this->resource->timezone,
            'latitude' => $this->resource->latitude === null ? null : (float) $this->resource->latitude,
            'longitude' => $this->resource->longitude === null ? null : (float) $this->resource->longitude,
        ];
    }
}
