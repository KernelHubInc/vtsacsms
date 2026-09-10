<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class MaintenanceAssignmentRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'technician_user_id' => ['nullable', 'ulid', 'required_without:vendor_organization_id', Rule::exists('users', 'public_id')],
            'vendor_organization_id' => ['nullable', 'ulid', 'required_without:technician_user_id', 'prohibited_with:technician_user_id', Rule::exists('organizations', 'id')->where('tenant_id', $tenant)],
        ];
    }
}
