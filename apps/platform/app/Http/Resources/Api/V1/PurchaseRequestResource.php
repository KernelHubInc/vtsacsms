<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'request_number' => (string) $this->resource->request_number,
            'department_id' => (string) $this->resource->department_id,
            'cost_center_id' => (string) $this->resource->cost_center_id,
            'status' => $this->resource->status->value,
            'revision' => (int) $this->resource->revision,
            'currency' => (string) $this->resource->currency,
            'total_minor' => (int) $this->resource->total_minor,
            'needed_by' => $this->resource->needed_by?->format('Y-m-d'),
            'business_reason' => (string) $this->resource->business_reason,
            'decision_reason' => $this->resource->decision_reason,
            'lines' => $this->whenLoaded('lines', fn (): mixed => $this->resource->lines->map(
                static fn ($line): array => [
                    'id' => (string) $line->getKey(),
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'description' => (string) $line->description,
                    'quantity_base' => (int) $line->quantity_base,
                    'estimated_unit_minor' => (int) $line->estimated_unit_minor,
                    'estimated_total_minor' => (int) $line->estimated_total_minor,
                ],
            )),
        ];
    }
}
