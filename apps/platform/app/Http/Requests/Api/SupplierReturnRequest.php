<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class SupplierReturnRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'return_number' => [
                'required', 'string', 'max:80',
                Rule::unique('inventory_returns')->where('tenant_id', $this->tenantId()),
            ],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
