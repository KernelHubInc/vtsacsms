<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\DocumentApproval;
use App\Modules\Procurement\Notifications\ApprovalRequiredNotification;
use App\Modules\Procurement\Notifications\DiscrepancyOpenedNotification;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

final readonly class ProcurementNotifier
{
    public function __construct(private CurrentTenant $tenant) {}

    public function approvalRequired(DocumentApproval $approval, string $reference): void
    {
        $this->sendToRole(
            (string) $approval->approver_role_key,
            new ApprovalRequiredNotification(
                (string) $approval->document_type,
                (string) $approval->document_id,
                $reference,
            ),
        );
    }

    public function discrepancyOpened(string $invoiceId, string $reference, int $issueCount): void
    {
        $this->sendToPermission(
            PermissionKey::ProcurementApprove,
            new DiscrepancyOpenedNotification($invoiceId, $reference, $issueCount),
        );
    }

    private function sendToRole(string $roleKey, Notification $notification): void
    {
        $userIds = DB::table('memberships')
            ->join('role_assignments', function ($join): void {
                $join->on('role_assignments.tenant_id', '=', 'memberships.tenant_id')
                    ->on('role_assignments.membership_id', '=', 'memberships.id');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.tenant_id', '=', 'role_assignments.tenant_id')
                    ->on('roles.id', '=', 'role_assignments.role_id');
            })
            ->where('memberships.tenant_id', $this->tenant->get()->tenantId)
            ->where('memberships.status', 'active')
            ->where('roles.key', $roleKey)
            ->pluck('memberships.user_id');
        NotificationFacade::send(User::query()->whereIn('id', $userIds)->get(), $notification);
    }

    private function sendToPermission(PermissionKey $permission, Notification $notification): void
    {
        $userIds = DB::table('memberships')
            ->join('role_assignments', function ($join): void {
                $join->on('role_assignments.tenant_id', '=', 'memberships.tenant_id')
                    ->on('role_assignments.membership_id', '=', 'memberships.id');
            })
            ->join('role_permissions', function ($join): void {
                $join->on('role_permissions.tenant_id', '=', 'role_assignments.tenant_id')
                    ->on('role_permissions.role_id', '=', 'role_assignments.role_id');
            })
            ->where('memberships.tenant_id', $this->tenant->get()->tenantId)
            ->where('memberships.status', 'active')
            ->where('role_permissions.permission_key', $permission->value)
            ->pluck('memberships.user_id');
        NotificationFacade::send(User::query()->whereIn('id', $userIds)->get(), $notification);
    }
}
