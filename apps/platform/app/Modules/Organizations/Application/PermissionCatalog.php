<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Models\Permission;
use App\Modules\Organizations\Domain\PermissionKey;

final class PermissionCatalog
{
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
