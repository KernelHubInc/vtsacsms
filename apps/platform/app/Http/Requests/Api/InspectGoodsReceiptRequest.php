<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class InspectGoodsReceiptRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array', 'min:1', 'max:500'],
            'decisions.*.line_id' => ['required', 'ulid', 'distinct'],
            'decisions.*.accepted_quantity_base' => ['required', 'integer', 'min:0'],
            'decisions.*.rejected_quantity_base' => ['required', 'integer', 'min:0'],
            'decisions.*.inspection_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
