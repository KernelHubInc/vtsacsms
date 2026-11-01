<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OperatorOnboarding
{
    public function allowed(User $actor): bool
    {
        foreach ([PermissionKey::PlatformPanelAccess, PermissionKey::OrganizationManage, PermissionKey::InvitationManage, PermissionKey::RoleAssign] as $permission) {
            if (! app(AuthorizationService::class)->allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function roles(User $actor): array
    {
        $roles = [];
        foreach (Role::query()->with('permissions')->orderBy('name')->get() as $role) {
            $permissions = $role->permissions->pluck('key')->all();
            if (! in_array(PermissionKey::OperatorPanelAccess->value, $permissions, true)
                || in_array(PermissionKey::PlatformPanelAccess->value, $permissions, true)) {
                continue;
            }
            foreach ($permissions as $value) {
                $permission = PermissionKey::tryFrom($value);
                if ($permission === null || ! app(AuthorizationService::class)->allows($actor, $permission)) {
                    continue 2;
                }
            }
            $roles[(string) $role->getKey()] = $role->name;
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{organization_id:string,invitation_id:string}
     */
    public function create(User $actor, string $requestId, array $data): array
    {
        abort_unless($this->allowed($actor), 403);
        Validator::make(['request_id' => $requestId] + $data, [
            'request_id' => ['required', 'ulid'], 'mode' => ['required', 'in:new,existing'],
            'name' => ['required_if:mode,new', 'nullable', 'string', 'max:180'],
            'code' => ['required_if:mode,new', 'nullable', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'],
            'organization_id' => ['required_if:mode,existing', 'nullable', 'ulid'],
            'email' => ['required', 'email', 'max:254'], 'role_id' => ['required', 'ulid'],
            'scope' => ['required', 'in:organization,site'], 'site_id' => ['required_if:scope,site', 'nullable', 'ulid'],
        ])->validate();
        if (! array_key_exists($data['role_id'], $this->roles($actor))) {
            throw ValidationException::withMessages(['role_id' => 'Choose an operator role you are authorized to delegate.']);
        }
        $tenant = app(CurrentTenant::class)->get()->tenantId;
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $requestId, $data, $tenant, $hash): array {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $previous = DB::table('operator_onboardings')->where('id', $requestId)->first();
            if ($previous !== null) {
                abort_unless($previous->tenant_id === $tenant && $previous->actor_public_id === $actor->public_id, 404);
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw ValidationException::withMessages(['code' => 'This request was already completed. Start a new onboarding.']);
                }

                return ['organization_id' => $previous->organization_id, 'invitation_id' => $previous->invitation_id];
            }
            if ($data['mode'] === 'new') {
                $code = Str::upper(trim($data['code']));
                if (Organization::query()->whereRaw('lower(code) = ?', [Str::lower($code)])->exists()) {
                    throw ValidationException::withMessages(['code' => 'This code already exists. Select the existing organization or use another code.']);
                }
                $organization = Organization::query()->create(['name' => trim($data['name']), 'code' => $code, 'type' => OrganizationType::ChargePointOperator, 'is_active' => true]);
                app(AuditRecorder::class)->record(new AuditEntry('organizations.organization.created', 'organization', (string) $organization->getKey(), AuditResult::Succeeded, after: $organization->only(['name', 'code', 'type', 'is_active'])));
            } else {
                $organization = Organization::query()->where('type', OrganizationType::ChargePointOperator)->where('is_active', true)->whereKey($data['organization_id'])->firstOrFail();
            }
            $scope = new ResourceScope(ScopeType::Organization, (string) $organization->getKey());
            if ($data['scope'] === 'site') {
                $site = app(AccessibleSitesQuery::class)->for($actor)->where('operator_organization_id', $organization->getKey())->whereKey($data['site_id'])->first();
                if ($site === null) {
                    throw ValidationException::withMessages(['site_id' => 'Select a site belonging to this operator.']);
                }
                $scope = new ResourceScope(ScopeType::Site, (string) $site->getKey());
            }
            $issued = app(InvitationService::class)->invite($actor, $data['email'], $organization, Role::query()->whereKey($data['role_id'])->firstOrFail(), $scope);
            $result = ['organization_id' => (string) $organization->getKey(), 'invitation_id' => (string) $issued->invitation->getKey()];
            DB::table('operator_onboardings')->insert(['id' => $requestId, 'tenant_id' => $tenant, 'actor_public_id' => $actor->public_id, 'request_hash' => $hash, 'created_at' => now('UTC')] + $result);
            app(AuditRecorder::class)->record(new AuditEntry('organizations.operator.onboarded', 'organization', $result['organization_id'], AuditResult::Succeeded, after: ['invitation_id' => $result['invitation_id'], 'scope_type' => $scope->type->value, 'scope_id' => $scope->id]));

            return $result;
        });
    }
}
