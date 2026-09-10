<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class MaintenanceResolutionRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'work_performed' => ['required', 'string', 'max:20000'],
            'diagnosis' => ['nullable', 'string', 'max:10000'],
            'failure_code_id' => ['nullable', 'ulid', Rule::exists('maintenance_codes', 'id')->where('tenant_id', $tenant)->where('type', 'failure')],
            'root_cause_code_id' => ['nullable', 'ulid', Rule::exists('maintenance_codes', 'id')->where('tenant_id', $tenant)->where('type', 'root_cause')],
            'resolution_code_id' => ['required', 'ulid', Rule::exists('maintenance_codes', 'id')->where('tenant_id', $tenant)->where('type', 'resolution')],
        ];
    }
}
