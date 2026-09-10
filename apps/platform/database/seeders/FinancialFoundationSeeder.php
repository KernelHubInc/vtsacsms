<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialFoundationSeeder extends Seeder
{
    public function run(CurrentTenant $currentTenant): void
    {
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($currentTenant): void {
            $currentTenant->run(new TenantContext((string) $tenant->getKey(), ActorType::Platform, null, (string) Str::ulid()), function () use ($tenant): void {
                PaymentProviderConfig::query()->firstOrCreate(['provider' => 'fake', 'configuration_version' => 1], [
                    'environment' => 'local', 'api_version' => 'v1', 'capabilities' => [
                        'authorization' => true, 'incremental_authorization' => true, 'capture' => true,
                        'refund' => true, 'reconciliation_export' => true, 'settlement' => true,
                    ], 'is_active' => true,
                ]);
                $role = Role::query()->updateOrCreate(['tenant_id' => $tenant->getKey(), 'key' => 'finance-reviewer'],
                    ['name' => 'Finance reviewer', 'is_system' => true]);
                DB::table('role_permissions')->where('tenant_id', $tenant->getKey())->where('role_id', $role->getKey())->delete();
                DB::table('role_permissions')->insert(array_map(static fn (PermissionKey $permission): array => [
                    'tenant_id' => $tenant->getKey(), 'role_id' => $role->getKey(), 'permission_key' => $permission->value, 'created_at' => now('UTC'),
                ], [PermissionKey::OperatorPanelAccess, PermissionKey::PaymentView, PermissionKey::PaymentRefund,
                    PermissionKey::BillingView, PermissionKey::SettlementView, PermissionKey::SettlementPrepare,
                    PermissionKey::SettlementApprove, PermissionKey::ReportingView, PermissionKey::ReportingExport]));
            });
        });
    }
}
