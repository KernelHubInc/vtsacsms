<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class ExportVendorInvoiceRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:160']];
    }
}
