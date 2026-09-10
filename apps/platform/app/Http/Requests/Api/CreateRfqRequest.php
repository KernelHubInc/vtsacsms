<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class CreateRfqRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'rfq_number' => ['required', 'string', 'max:80', Rule::unique('procurement_rfqs')->where('tenant_id', $tenantId)],
            'supplier_ids' => ['required', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => [
                'required', 'ulid', 'distinct',
                Rule::exists('procurement_suppliers', 'id')->where('tenant_id', $tenantId)->where('status', 'active'),
            ],
            'closes_at' => ['required', 'date', 'after:now'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
