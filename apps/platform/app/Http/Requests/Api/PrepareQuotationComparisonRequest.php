<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class PrepareQuotationComparisonRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'selected_quotation_id' => [
                'required', 'ulid',
                Rule::exists('supplier_quotations', 'id')->where('tenant_id', $this->tenantId()),
            ],
            'selection_reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
