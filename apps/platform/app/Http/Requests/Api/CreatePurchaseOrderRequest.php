<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class CreatePurchaseOrderRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'po_number' => ['required', 'string', 'max:80', Rule::unique('purchase_orders')->where('tenant_id', $tenantId)],
            'delivery_warehouse_id' => [
                'nullable', 'ulid', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId),
            ],
            'terms' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
