<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'item_id' => (string) $this->resource->item_id,
            'uom_id' => (string) $this->resource->uom_id,
            'from_bin_id' => $this->resource->from_bin_id,
            'to_bin_id' => $this->resource->to_bin_id,
            'lot_id' => $this->resource->lot_id,
            'serial_id' => $this->resource->serial_id,
            'movement_type' => $this->resource->movement_type->value,
            'quantity_base' => (int) $this->resource->quantity_base,
            'currency' => (string) $this->resource->currency,
            'unit_cost_minor' => (int) $this->resource->unit_cost_minor,
            'total_cost_minor' => (int) $this->resource->total_cost_minor,
            'reference_type' => (string) $this->resource->reference_type,
            'reference_id' => (string) $this->resource->reference_id,
            'reason_code' => $this->resource->reason_code,
            'occurred_at' => $this->resource->occurred_at->utc()->toISOString(),
            'posted_at' => $this->resource->posted_at->utc()->toISOString(),
        ];
    }
}
