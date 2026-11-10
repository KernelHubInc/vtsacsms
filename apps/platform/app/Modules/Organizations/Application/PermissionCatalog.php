<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Models\Permission;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Support\Str;

final class PermissionCatalog
{
    /** @return array<string, array{label: string, permissions: array<string, string>}> */
    public function modules(): array
    {
        $labels = [
            'panels' => 'Portal access',
            'identity' => 'People and access',
            'kyc' => 'Identity verification (KYC)',
            'audit' => 'Audit trail',
            'tenancy' => 'Tenant settings',
            'organizations' => 'Organizations',
            'charging_accounts' => 'Charging accounts',
            'locations' => 'Locations',
            'assets' => 'Charging equipment',
            'charging' => 'Charging operations',
            'tariffs' => 'Tariffs',
            'payments' => 'Payments',
            'billing' => 'Billing',
            'settlements' => 'Settlements',
            'procurement' => 'Procurement',
            'inventory' => 'Inventory',
            'maintenance' => 'Maintenance',
            'support' => 'Support',
            'reporting' => 'Reports and exports',
            'cms' => 'Content studio',
            'integrations' => 'Integrations',
        ];
        $modules = [];
        foreach ($labels as $key => $label) {
            $modules[$key] = ['label' => $label, 'permissions' => []];
        }

        foreach (PermissionKey::cases() as $permission) {
            $parts = explode('.', $permission->value);
            $module = array_shift($parts);
            if ($module === 'identity' && in_array($parts[0], ['panels', 'kyc'], true)) {
                $module = array_shift($parts);
            } elseif ($module === 'organizations' && $parts[0] === 'charging_accounts') {
                $module = array_shift($parts);
            }

            if (! isset($modules[$module])) {
                throw new \LogicException('Every permission must belong to a named module.');
            }
            $modules[$module]['permissions'][$permission->value] = implode(' · ', array_map(Str::headline(...), $parts));
        }

        return $modules;
    }

    /**
     * @return list<array{key: string, context: string, description: string, created_at: mixed, updated_at: mixed}>
     */
    public function records(): array
    {
        $now = now('UTC');

        return array_values(array_map(
            fn (PermissionKey $permission): array => [
                'key' => $permission->value,
                'context' => explode('.', $permission->value, 2)[0],
                'description' => $this->description($permission),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            PermissionKey::cases(),
        ));
    }

    public function sync(): void
    {
        Permission::query()->upsert(
            $this->records(),
            ['key'],
            ['context', 'description', 'updated_at'],
        );
    }

    private function description(PermissionKey $permission): string
    {
        return match ($permission) {
            PermissionKey::IdentityContextView => 'View the authenticated identity and tenant context.',
            PermissionKey::PlatformPanelAccess => 'Access the platform administration panel.',
            PermissionKey::OperatorPanelAccess => 'Access the tenant operator panel.',
            PermissionKey::MembershipView => 'View tenant memberships.',
            PermissionKey::MembershipManage => 'Invite, suspend, and revoke tenant memberships.',
            PermissionKey::InvitationManage => 'Issue and revoke tenant invitations.',
            PermissionKey::RoleView => 'View roles and their permissions.',
            PermissionKey::RoleManage => 'Create and update tenant role bundles.',
            PermissionKey::RoleAssign => 'Assign or revoke roles within held authority.',
            PermissionKey::TokenIssue => 'Issue reduced-scope API tokens.',
            PermissionKey::TokenRevoke => 'Revoke API tokens and sessions.',
            PermissionKey::SessionView => 'List the subject devices and browser sessions.',
            PermissionKey::SessionRevoke => 'Revoke the subject devices and browser sessions.',
            PermissionKey::AuditView => 'View tenant audit evidence.',
            PermissionKey::AuditExport => 'Export tenant audit evidence.',
            default => 'Perform the named action within an authorized tenant and resource scope.',
        };
    }
}
