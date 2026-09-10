<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class StoreMaintenanceWorkOrderRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'work_type' => ['required', Rule::in(['corrective', 'preventive', 'inspection', 'vendor_repair'])],
            'site_id' => ['required', 'ulid', Rule::exists('sites', 'id')->where('tenant_id', $tenant)],
            'asset_type' => ['required', Rule::in(['station', 'evse', 'connector', 'component'])],
            'asset_id' => ['required', 'ulid'],
            'priority_id' => ['required', 'ulid', Rule::exists('maintenance_priorities', 'id')->where('tenant_id', $tenant)->where('is_active', true)],
            'sla_policy_id' => ['nullable', 'ulid', Rule::exists('maintenance_sla_policies', 'id')->where('tenant_id', $tenant)],
            'checklist_template_id' => ['nullable', 'ulid', Rule::exists('maintenance_checklist_templates', 'id')->where('tenant_id', $tenant)->where('is_active', true)],
            'warranty_id' => ['nullable', 'ulid', Rule::exists('maintenance_warranties', 'id')->where('tenant_id', $tenant)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'scope' => ['nullable', 'string', 'max:10000'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'estimated_cost_minor' => ['sometimes', 'integer', 'min:0'],
            'safety_critical' => ['sometimes', 'boolean'],
            'verification_required' => ['sometimes', 'boolean'],
            'asset_restriction_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *   work_type:string,site_id:string,asset_type:string,asset_id:string,priority_id:string,
     *   sla_policy_id:string|null,checklist_template_id:string|null,warranty_id:string|null,
     *   title:string,description:string,scope:string|null,currency:string,estimated_cost_minor:int,
     *   safety_critical:bool,verification_required:bool,asset_restriction_required:bool
     * }
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'work_type' => (string) $data['work_type'],
            'site_id' => (string) $data['site_id'],
            'asset_type' => (string) $data['asset_type'],
            'asset_id' => (string) $data['asset_id'],
            'priority_id' => (string) $data['priority_id'],
            'sla_policy_id' => isset($data['sla_policy_id']) ? (string) $data['sla_policy_id'] : null,
            'checklist_template_id' => isset($data['checklist_template_id'])
                ? (string) $data['checklist_template_id']
                : null,
            'warranty_id' => isset($data['warranty_id']) ? (string) $data['warranty_id'] : null,
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'scope' => isset($data['scope']) ? (string) $data['scope'] : null,
            'currency' => (string) ($data['currency'] ?? 'PHP'),
            'estimated_cost_minor' => (int) ($data['estimated_cost_minor'] ?? 0),
            'safety_critical' => (bool) ($data['safety_critical'] ?? false),
            'verification_required' => (bool) ($data['verification_required'] ?? false),
            'asset_restriction_required' => (bool) ($data['asset_restriction_required'] ?? false),
        ];
    }
}
