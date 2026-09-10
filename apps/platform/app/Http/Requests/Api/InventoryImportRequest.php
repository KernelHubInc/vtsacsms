<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class InventoryImportRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
