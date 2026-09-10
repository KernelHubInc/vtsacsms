<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FoundationRoleSeeder extends Seeder
{
    public function run(CurrentTenant $currentTenant): void
    {
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($currentTenant): void {
            $context = new TenantContext(
                tenantId: (string) $tenant->getKey(),
                actorType: ActorType::Platform,
                actorId: null,
                correlationId: (string) Str::ulid(),
            );

            $currentTenant->run($context, function () use ($tenant): void {
                $operatorRole = Role::query()->updateOrCreate(
                    ['tenant_id' => $tenant->getKey(), 'key' => 'operator-administrator'],
                    ['name' => 'Operator administrator', 'is_system' => true],
                );
                $siteRole = Role::query()->updateOrCreate(
                    ['tenant_id' => $tenant->getKey(), 'key' => 'site-viewer'],
                    ['name' => 'Site viewer', 'is_system' => true],
                );

                $this->syncPermissions($tenant, $operatorRole, [
                    PermissionKey::OperatorPanelAccess,
                    PermissionKey::IdentityContextView,
                    PermissionKey::MembershipView,
                    PermissionKey::MembershipManage,
                    PermissionKey::InvitationManage,
                    PermissionKey::RoleView,
                    PermissionKey::RoleAssign,
                    PermissionKey::SessionView,
                    PermissionKey::SessionRevoke,
                    PermissionKey::LocationView,
                    PermissionKey::LocationManage,
                    PermissionKey::AssetView,
                    PermissionKey::AssetManage,
                    PermissionKey::ChargingSessionView,
                    PermissionKey::ChargingRemoteCommand,
                    PermissionKey::ChargingSessionReview,
                    PermissionKey::TariffView,
                    PermissionKey::TariffManage,
                    PermissionKey::TariffPublish,
                    PermissionKey::PaymentView,
                    PermissionKey::PaymentRefund,
                    PermissionKey::BillingView,
                    PermissionKey::SettlementView,
                    PermissionKey::SettlementPrepare,
                    PermissionKey::ReportingView,
                    PermissionKey::ReportingExport,
                ]);
                $this->syncPermissions($tenant, $siteRole, [
                    PermissionKey::IdentityContextView,
                    PermissionKey::SessionView,
                    PermissionKey::SessionRevoke,
                    PermissionKey::LocationView,
                    PermissionKey::AssetView,
                    PermissionKey::ChargingSessionView,
                    PermissionKey::TariffView,
                    PermissionKey::ReportingView,
                    PermissionKey::ReportingExport,
                ]);
            });
        });
    }

    /** @param list<PermissionKey> $permissions */
    private function syncPermissions(Tenant $tenant, Role $role, array $permissions): void
    {
        DB::table('role_permissions')
            ->where('tenant_id', $tenant->getKey())
            ->where('role_id', $role->getKey())
            ->delete();
        DB::table('role_permissions')->insert(array_map(
            static fn (PermissionKey $permission): array => [
                'tenant_id' => $tenant->getKey(),
                'role_id' => $role->getKey(),
                'permission_key' => $permission->value,
                'created_at' => now('UTC'),
            ],
            $permissions,
        ));
    }
}
