<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class StoreAdjustmentRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'adjustment_number' => [
                'required', 'string', 'max:80',
                Rule::unique('inventory_adjustment_requests')->where('tenant_id', $tenantId),
            ],
            'warehouse_id' => ['required', 'ulid', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)],
            'reason_code' => ['required', 'string', 'max:80'],
            'reason_notes' => ['required', 'string', 'max:5000'],
            'source_type' => ['nullable', 'string', 'max:48'],
            'source_id' => ['nullable', 'ulid', 'required_with:source_type'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.uom_id' => [
                'required', 'ulid', Rule::exists('inventory_units_of_measure', 'id')->where('tenant_id', $tenantId),
            ],
            'lines.*.bin_id' => ['required', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId)],
            'lines.*.lot_id' => ['nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_id' => ['nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity_delta_base' => ['required', 'integer', 'not_in:0'],
            'lines.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ];
    }
}
