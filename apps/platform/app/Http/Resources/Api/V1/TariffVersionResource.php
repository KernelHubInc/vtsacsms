<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TariffVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'tariff_id' => (string) $this->resource->tariff_id,
            'version' => (int) $this->resource->version,
            'status' => $this->resource->status->value,
            'effective_from' => $this->resource->effective_from->utc()->toISOString(),
            'effective_to' => $this->resource->effective_to?->utc()->toISOString(),
            'tax_treatment' => $this->resource->tax_treatment->value,
            'tax_rate_basis_points' => $this->resource->tax_rate_basis_points,
            'minimum_fee_minor' => $this->resource->minimum_fee_minor,
            'maximum_fee_minor' => $this->resource->maximum_fee_minor,
            'operator_id' => $this->resource->operator_id,
            'site_id' => $this->resource->site_id,
            'connector_id' => $this->resource->connector_id,
            'timezone' => (string) $this->resource->timezone,
            'components' => $this->whenLoaded('components', fn () => $this->resource->components->map(fn ($component): array => [
                'id' => (string) $component->getKey(),
                'dimension' => $component->dimension->value,
                'price_minor' => (int) $component->price_minor,
                'unit_quantity' => (int) $component->unit_quantity,
                'day_of_week_mask' => (int) $component->day_of_week_mask,
                'starts_at_local' => $component->starts_at_local,
                'ends_at_local' => $component->ends_at_local,
                'priority' => (int) $component->priority,
            ])->all()),
            'discounts' => $this->whenLoaded('discounts', fn () => $this->resource->discounts->map(fn ($discount): array => [
                'id' => (string) $discount->getKey(),
                'name' => (string) $discount->name,
                'code' => $discount->code,
                'kind' => $discount->kind->value,
                'value' => (int) $discount->value,
                'is_automatic' => (bool) $discount->is_automatic,
                'effective_from' => $discount->effective_from?->utc()->toISOString(),
                'effective_to' => $discount->effective_to?->utc()->toISOString(),
            ])->all()),
        ];
    }
}
