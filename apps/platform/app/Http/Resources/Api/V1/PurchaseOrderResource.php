<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'po_number' => (string) $this->resource->po_number,
            'purchase_request_id' => (string) $this->resource->purchase_request_id,
            'supplier_id' => (string) $this->resource->supplier_id,
            'status' => $this->resource->status->value,
            'revision' => (int) $this->resource->revision,
            'currency' => (string) $this->resource->currency,
            'subtotal_minor' => (int) $this->resource->subtotal_minor,
            'tax_minor' => (int) $this->resource->tax_minor,
            'shipping_minor' => (int) $this->resource->shipping_minor,
            'total_minor' => (int) $this->resource->total_minor,
            'delivery_warehouse_id' => $this->resource->delivery_warehouse_id,
            'lines' => $this->whenLoaded('lines', fn (): mixed => $this->resource->lines->map(
                static fn ($line): array => [
                    'id' => (string) $line->getKey(),
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'description' => (string) $line->description,
                    'ordered_quantity_base' => (int) $line->ordered_quantity_base,
                    'received_quantity_base' => (int) $line->received_quantity_base,
                    'accepted_quantity_base' => (int) $line->accepted_quantity_base,
                    'returned_quantity_base' => (int) $line->returned_quantity_base,
                    'unit_price_minor' => (int) $line->unit_price_minor,
                    'line_total_minor' => (int) $line->line_total_minor,
                ],
            )),
        ];
    }
}
