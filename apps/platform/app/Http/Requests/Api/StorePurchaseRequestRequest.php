<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;
use LogicException;

final class StorePurchaseRequestRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'request_number' => [
                'required', 'string', 'max:80',
                Rule::unique('purchase_requests')->where('tenant_id', $tenantId),
            ],
            'department_id' => [
                'required', 'ulid',
                Rule::exists('procurement_departments', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'cost_center_id' => [
                'required', 'ulid',
                Rule::exists('procurement_cost_centers', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'business_reason' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['nullable', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.uom_id' => ['nullable', 'ulid', Rule::exists('inventory_units_of_measure', 'id')->where('tenant_id', $tenantId)],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity_base' => ['required', 'integer', 'min:1'],
            'lines.*.estimated_unit_minor' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{
     *   request_number: string, department_id: string, cost_center_id: string, currency: string,
     *   needed_by: string|null, business_reason: string,
     *   lines: list<array{
     *     item_id: string|null, uom_id: string|null, description: string,
     *     quantity_base: int, estimated_unit_minor: int
     *   }>
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();
        $rawLines = $data['lines'] ?? null;
        if (! is_array($rawLines)) {
            throw new LogicException('Validated purchase-request lines are unavailable.');
        }
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw new LogicException('A validated purchase-request line is invalid.');
            }
            $lines[] = [
                'item_id' => isset($rawLine['item_id']) ? (string) $rawLine['item_id'] : null,
                'uom_id' => isset($rawLine['uom_id']) ? (string) $rawLine['uom_id'] : null,
                'description' => (string) $rawLine['description'],
                'quantity_base' => (int) $rawLine['quantity_base'],
                'estimated_unit_minor' => (int) $rawLine['estimated_unit_minor'],
            ];
        }

        return [
            'request_number' => (string) $data['request_number'],
            'department_id' => (string) $data['department_id'],
            'cost_center_id' => (string) $data['cost_center_id'],
            'currency' => (string) $data['currency'],
            'needed_by' => isset($data['needed_by']) ? (string) $data['needed_by'] : null,
            'business_reason' => (string) $data['business_reason'],
            'lines' => $lines,
        ];
    }
}
