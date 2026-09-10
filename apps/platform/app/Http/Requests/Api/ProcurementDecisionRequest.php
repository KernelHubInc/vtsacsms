<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class ProcurementDecisionRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approver_role_key' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
