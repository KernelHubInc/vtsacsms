<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VendorInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'purchase_order_id' => (string) $this->resource->purchase_order_id,
            'supplier_id' => (string) $this->resource->supplier_id,
            'invoice_reference' => (string) $this->resource->invoice_reference,
            'status' => $this->resource->status->value,
            'currency' => (string) $this->resource->currency,
            'subtotal_minor' => (int) $this->resource->subtotal_minor,
            'tax_minor' => (int) $this->resource->tax_minor,
            'total_minor' => (int) $this->resource->total_minor,
            'accounting_export_state' => (string) $this->resource->accounting_export_state,
            'invoice_date' => $this->resource->invoice_date->format('Y-m-d'),
            'due_date' => $this->resource->due_date?->format('Y-m-d'),
            'lines' => $this->whenLoaded('lines', fn (): mixed => $this->resource->lines->map(
                static fn ($line): array => [
                    'id' => (string) $line->getKey(),
                    'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                    'quantity_base' => (int) $line->quantity_base,
                    'unit_price_minor' => (int) $line->unit_price_minor,
                    'total_minor' => (int) $line->total_minor,
                ],
            )),
        ];
    }
}
