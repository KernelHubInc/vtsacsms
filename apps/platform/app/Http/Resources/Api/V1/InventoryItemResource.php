<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'sku' => (string) $this->resource->sku,
            'name' => (string) $this->resource->name,
            'description' => $this->resource->description,
            'category_id' => (string) $this->resource->category_id,
            'base_uom_id' => (string) $this->resource->base_uom_id,
            'tracking_type' => $this->resource->tracking_type->value,
            'valuation_method' => $this->resource->valuation_method->value,
            'currency' => (string) $this->resource->currency,
            'standard_cost_minor' => $this->resource->standard_cost_minor,
            'is_active' => (bool) $this->resource->is_active,
        ];
    }
}
