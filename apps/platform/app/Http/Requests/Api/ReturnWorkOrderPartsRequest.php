<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class ReturnWorkOrderPartsRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'work_order_id' => ['required', 'ulid'],
            'item_id' => ['required', 'ulid', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'uom_id' => [
                'required', 'ulid', Rule::exists('inventory_units_of_measure', 'id')->where('tenant_id', $tenantId),
            ],
            'destination_bin_id' => [
                'required', 'ulid', Rule::exists('inventory_bins', 'id')->where('tenant_id', $tenantId),
            ],
            'quantity_base' => ['required', 'integer', 'min:1'],
            'unit_cost_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'lot_id' => ['nullable', 'ulid', Rule::exists('inventory_lots', 'id')->where('tenant_id', $tenantId)],
            'serial_id' => ['nullable', 'ulid', Rule::exists('inventory_serials', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
