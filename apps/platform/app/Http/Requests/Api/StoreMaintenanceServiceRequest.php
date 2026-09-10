<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class StoreMaintenanceServiceRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'site_id' => ['required', 'ulid', Rule::exists('sites', 'id')->where('tenant_id', $tenant)],
            'asset_type' => ['nullable', Rule::in(['station', 'evse', 'connector', 'component']), 'required_with:asset_id'],
            'asset_id' => ['nullable', 'ulid', 'required_with:asset_type'],
            'priority_id' => ['nullable', 'ulid', Rule::exists('maintenance_priorities', 'id')->where('tenant_id', $tenant)],
            'source' => ['sometimes', Rule::in(['operator', 'technician', 'support', 'customer'])],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array{
     *   site_id:string,title:string,description:string,source:string,
     *   asset_type:string|null,asset_id:string|null,priority_id:string|null
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'site_id' => (string) $data['site_id'],
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'source' => (string) ($data['source'] ?? 'operator'),
            'asset_type' => isset($data['asset_type']) ? (string) $data['asset_type'] : null,
            'asset_id' => isset($data['asset_id']) ? (string) $data['asset_id'] : null,
            'priority_id' => isset($data['priority_id']) ? (string) $data['priority_id'] : null,
        ];
    }
}
