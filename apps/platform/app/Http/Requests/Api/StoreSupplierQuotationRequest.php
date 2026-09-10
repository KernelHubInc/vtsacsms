<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class StoreSupplierQuotationRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'supplier_id' => ['required', 'ulid', Rule::exists('procurement_suppliers', 'id')->where('tenant_id', $tenantId)],
            'quotation_reference' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'tax_minor' => ['sometimes', 'integer', 'min:0'],
            'shipping_minor' => ['sometimes', 'integer', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'commercial_terms' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_request_line_id' => [
                'required', 'ulid', Rule::exists('purchase_request_lines', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.quantity_base' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'lines.*.offered_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{
     *   quotation_reference: string, currency: string, tax_minor: int, shipping_minor: int,
     *   lead_time_days: int|null, valid_until: string|null,
     *   commercial_terms: array<array-key, mixed>|null,
     *   lines: list<array{
     *     purchase_request_line_id: string, quantity_base: int, unit_price_minor: int,
     *     offered_description: string|null
     *   }>
     * }
     */
    public function quotationPayload(): array
    {
        $data = $this->validated();
        $rawLines = $data['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Validated quotation lines are unavailable.');
        }
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated quotation line is invalid.');
            }
            $lines[] = [
                'purchase_request_line_id' => (string) $rawLine['purchase_request_line_id'],
                'quantity_base' => (int) $rawLine['quantity_base'],
                'unit_price_minor' => (int) $rawLine['unit_price_minor'],
                'offered_description' => isset($rawLine['offered_description'])
                    ? (string) $rawLine['offered_description']
                    : null,
            ];
        }
        $terms = $data['commercial_terms'] ?? null;
        if ($terms !== null && ! is_array($terms)) {
            throw new LogicException('Validated commercial terms are invalid.');
        }

        return [
            'quotation_reference' => (string) $data['quotation_reference'],
            'currency' => (string) $data['currency'],
            'tax_minor' => (int) ($data['tax_minor'] ?? 0),
            'shipping_minor' => (int) ($data['shipping_minor'] ?? 0),
            'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
            'valid_until' => isset($data['valid_until']) ? (string) $data['valid_until'] : null,
            'commercial_terms' => $terms,
            'lines' => $lines,
        ];
    }
}
