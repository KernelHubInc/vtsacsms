<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Models\User;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ApprovalActorGuard
{
    public function __construct(private CurrentTenant $tenant) {}

    public function assertAssignedRole(string $roleKey): void
    {
        $context = $this->tenant->get();
        $userId = User::query()->where('public_id', $context->actorId)->value('id');
        $assigned = $userId !== null && DB::table('memberships')
            ->join('role_assignments', function ($join): void {
                $join->on('role_assignments.tenant_id', '=', 'memberships.tenant_id')
                    ->on('role_assignments.membership_id', '=', 'memberships.id');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.tenant_id', '=', 'role_assignments.tenant_id')
                    ->on('roles.id', '=', 'role_assignments.role_id');
            })
            ->where('memberships.tenant_id', $context->tenantId)
            ->where('memberships.user_id', $userId)
            ->where('memberships.status', 'active')
            ->where('roles.key', $roleKey)
            ->where(function ($query): void {
                $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->where(function ($query): void {
                $query->whereNull('role_assignments.expires_at')->orWhere('role_assignments.expires_at', '>', now('UTC'));
            })
            ->exists();
        if (! $assigned) {
            throw new DomainException('The current actor is not assigned to this approval role.');
        }
    }

    public function assertDifferentActor(?string $originalActorId): void
    {
        $actorId = $this->tenant->get()->actorId;
        if ($actorId !== null && $originalActorId !== null && hash_equals($actorId, $originalActorId)) {
            throw new DomainException('The requester or preparer cannot approve their own document.');
        }
    }
}
