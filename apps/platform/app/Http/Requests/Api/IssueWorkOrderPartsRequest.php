<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class IssueWorkOrderPartsRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reservation_id' => [
                'required', 'ulid',
                Rule::exists('inventory_reservations', 'id')->where('tenant_id', $this->tenantId()),
            ],
            'source_bin_id' => [
                'required', 'ulid',
                Rule::exists('inventory_bins', 'id')->where('tenant_id', $this->tenantId()),
            ],
            'quantity_base' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ];
    }
}
