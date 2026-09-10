<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class MatchVendorInvoiceRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['amount_tolerance_minor' => ['sometimes', 'integer', 'min:0']];
    }
}
