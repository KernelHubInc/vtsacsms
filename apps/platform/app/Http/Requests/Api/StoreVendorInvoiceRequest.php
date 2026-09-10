<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class StoreVendorInvoiceRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'invoice_reference' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'tax_minor' => ['sometimes', 'integer', 'min:0'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_line_id' => [
                'required', 'ulid', Rule::exists('purchase_order_lines', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.quantity_base' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{
     *   invoice_reference: string, currency: string, tax_minor: int,
     *   invoice_date: string, due_date: string|null,
     *   lines: list<array{purchase_order_line_id: string, quantity_base: int, unit_price_minor: int}>
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();
        $rawLines = $data['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Validated vendor-invoice lines are unavailable.');
        }
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated vendor-invoice line is invalid.');
            }
            $lines[] = [
                'purchase_order_line_id' => (string) $rawLine['purchase_order_line_id'],
                'quantity_base' => (int) $rawLine['quantity_base'],
                'unit_price_minor' => (int) $rawLine['unit_price_minor'],
            ];
        }

        return [
            'invoice_reference' => (string) $data['invoice_reference'],
            'currency' => (string) $data['currency'],
            'tax_minor' => (int) ($data['tax_minor'] ?? 0),
            'invoice_date' => (string) $data['invoice_date'],
            'due_date' => isset($data['due_date']) ? (string) $data['due_date'] : null,
            'lines' => $lines,
        ];
    }
}
