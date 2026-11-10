<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Identity\Application\WorkforceEnrollmentService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\RegistrationTenantQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AdministratorBootstrap
{
    private const REVIEWED_PERMISSION_CATALOG = 'c40d8dddc6fcb693bfc396b6fb6990838065fc4347a8eb64ed8ed74cc9a4f21d';

    public function __construct(private CurrentTenant $tenant, private RegistrationTenantQuery $tenants, private WorkforceEnrollmentService $enrollments, private AuditRecorder $audit) {}

    /** @return list<PermissionKey> */
    public function administratorPermissions(): array
    {
        return [PermissionKey::IdentityContextView, PermissionKey::PlatformPanelAccess, PermissionKey::OperatorPanelAccess,
            PermissionKey::MembershipView, PermissionKey::MembershipManage, PermissionKey::InvitationManage,
            PermissionKey::RoleView, PermissionKey::RoleManage, PermissionKey::RoleAssign,
            PermissionKey::SessionView, PermissionKey::SessionRevoke, PermissionKey::AuditView,
            PermissionKey::TenantSettingsView, PermissionKey::OrganizationView, PermissionKey::LocationView,
            PermissionKey::AssetView, PermissionKey::ChargingSessionView, PermissionKey::TariffView,
            PermissionKey::PaymentView, PermissionKey::BillingView, PermissionKey::SettlementView,
            PermissionKey::ProcurementView, PermissionKey::InventoryView, PermissionKey::MaintenanceView,
            PermissionKey::SupportView, PermissionKey::ReportingView, PermissionKey::IntegrationView];
    }

    /** @return array{organization_id:string,super_subject_id:string,admin_subject_id:string,super_role_id:string,admin_role_id:string} */
    public function create(string $superEmail, string $adminEmail, string $organizationName, string $approvalReference, bool $resend = false): array
    {
        $context = $this->tenant->get();
        if ($context->actorType !== ActorType::Platform || ! $this->tenants->isActive($context->tenantId)) {
            throw new AuthorizationException('Initial administrators require a trusted platform operation in an active tenant.');
        }
        $superEmail = Str::lower(trim($superEmail));
        $adminEmail = Str::lower(trim($adminEmail));
        Validator::make(compact('superEmail', 'adminEmail', 'organizationName', 'approvalReference'), [
            'superEmail' => ['required', 'email', 'max:254', 'different:adminEmail'],
            'adminEmail' => ['required', 'email', 'max:254'],
            'organizationName' => ['required', 'string', 'max:180'],
            'approvalReference' => ['required', 'string', 'max:180'],
        ])->validate();
        $catalogHash = hash('sha256', json_encode(array_column(PermissionKey::cases(), 'value'), JSON_THROW_ON_ERROR));
        if (! hash_equals(self::REVIEWED_PERMISSION_CATALOG, $catalogHash) || count($this->administratorPermissions()) !== 27) {
            throw ValidationException::withMessages(['permissions' => 'Review and approve the administrator permission templates before bootstrapping this application version.']);
        }
        if (! app()->environment('testing') && (config('mail.default') !== 'smtp' || parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https')) {
            throw ValidationException::withMessages(['delivery' => 'Secure enrollment requires HTTPS and private SMTP delivery.']);
        }
        $planHash = hash('sha256', json_encode([$superEmail, $adminEmail, $organizationName, array_column(PermissionKey::cases(), 'value'), array_column($this->administratorPermissions(), 'value')], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($context, $superEmail, $adminEmail, $organizationName, $approvalReference, $planHash, $resend): array {
            DB::table('organization_administrator_bootstraps')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'tenant_id' => $context->tenantId, 'plan_hash' => $planHash, 'created_at' => now('UTC'),
            ]);
            $record = DB::table('organization_administrator_bootstraps')->where('tenant_id', $context->tenantId)->lockForUpdate()->first();
            if ($record === null || ! hash_equals($record->plan_hash, $planHash)) {
                throw ValidationException::withMessages(['bootstrap' => 'This tenant already has a different administrator bootstrap plan.']);
            }
            if ($record->organization_id !== null) {
                $result = $this->result($record);
                if ($resend) {
                    $this->enrollments->resend([$result['super_subject_id'], $result['admin_subject_id']], $planHash);
                }

                return $result;
            }
            $initialized = DB::table('role_assignments as assignments')->join('role_permissions as grants', function ($join): void {
                $join->on('grants.tenant_id', '=', 'assignments.tenant_id')->on('grants.role_id', '=', 'assignments.role_id');
            })->where('assignments.tenant_id', $context->tenantId)->where('grants.permission_key', PermissionKey::PlatformPanelAccess->value)->exists();
            if ($initialized) {
                throw ValidationException::withMessages(['bootstrap' => 'An administration grant already exists. Use normal authorized role management.']);
            }
            $organization = Organization::query()->where('type', OrganizationType::Platform)->first();
            if ($organization !== null && (! $organization->is_active || $organization->name !== $organizationName)) {
                throw ValidationException::withMessages(['organization' => 'Review the existing platform organization before bootstrapping.']);
            }
            $organization ??= Organization::query()->create(['name' => $organizationName, 'code' => 'PLATFORM', 'type' => OrganizationType::Platform, 'is_active' => true]);
            $result = ['organization_id' => (string) $organization->getKey()];
            foreach ([
                ['super', $superEmail, 'production-super-administrator', 'Production super administrator', true, PermissionKey::cases()],
                ['admin', $adminEmail, 'production-administrator', 'Production administrator', false, $this->administratorPermissions()],
            ] as [$kind, $email, $key, $name, $system, $permissions]) {
                $subject = $this->enrollments->prepare($email, $planHash);
                $role = Role::query()->create(['key' => $key, 'name' => $name, 'is_system' => $system]);
                foreach ($permissions as $permission) {
                    DB::table('role_permissions')->insert(['tenant_id' => $context->tenantId, 'role_id' => $role->getKey(), 'permission_key' => $permission->value, 'created_at' => now('UTC')]);
                }
                $membership = Membership::query()->create(['user_id' => $subject['user_id'], 'organization_id' => $organization->getKey(), 'status' => MembershipStatus::Active, 'joined_at' => now('UTC')]);
                $assignment = RoleAssignment::query()->create(['membership_id' => $membership->getKey(), 'role_id' => $role->getKey(), 'scope_type' => ScopeType::Tenant, 'scope_id' => null]);
                $this->audit->record(new AuditEntry('identity.role_assignment.bootstrapped', 'role_assignment', (string) $assignment->getKey(), AuditResult::Succeeded,
                    reason: $approvalReference, after: ['subject_id' => $subject['subject_id'], 'membership_id' => $membership->getKey(), 'role_id' => $role->getKey(), 'scope_type' => ScopeType::Tenant->value, 'permissions' => array_column($permissions, 'value')]));
                $result[$kind.'_subject_id'] = $subject['subject_id'];
                $result[$kind.'_role_id'] = (string) $role->getKey();
            }
            DB::table('organization_administrator_bootstraps')->where('id', $record->id)->update($result);
            $this->audit->record(new AuditEntry('organizations.administrators.bootstrapped', 'organization', (string) $organization->getKey(), AuditResult::Succeeded,
                reason: $approvalReference, after: $result));

            return $this->result((object) $result);
        });
    }

    /** @return array{organization_id:string,super_subject_id:string,admin_subject_id:string,super_role_id:string,admin_role_id:string} */
    private function result(object $record): array
    {
        $values = [];
        foreach (['organization_id', 'super_subject_id', 'admin_subject_id', 'super_role_id', 'admin_role_id'] as $key) {
            $value = $record->{$key} ?? null;
            if (! is_string($value) || ! Str::isUlid($value)) {
                throw new \LogicException('The administrator bootstrap record is incomplete.');
            }
            $values[$key] = $value;
        }

        return ['organization_id' => $values['organization_id'], 'super_subject_id' => $values['super_subject_id'], 'admin_subject_id' => $values['admin_subject_id'], 'super_role_id' => $values['super_role_id'], 'admin_role_id' => $values['admin_role_id']];
    }
}
