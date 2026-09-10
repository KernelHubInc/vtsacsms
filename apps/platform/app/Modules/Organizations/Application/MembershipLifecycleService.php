<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class MembershipLifecycleService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    public function suspend(User $actor, Membership $membership, string $reason): void
    {
        if (! $this->authorization->allows($actor, PermissionKey::MembershipManage, ResourceScope::tenant())) {
            throw new AuthorizationException('Membership suspension is not permitted.');
        }

        DB::transaction(function () use ($membership, $reason): void {
            $before = ['status' => $membership->status->value];
            $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
            MobileAccessToken::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $membership->user_id)
                ->delete();

            $this->audit->record(new AuditEntry(
                action: 'identity.membership.suspended',
                targetType: 'membership',
                targetId: (string) $membership->getKey(),
                result: AuditResult::Succeeded,
                reason: $reason,
                before: $before,
                after: ['status' => $membership->status->value],
            ));
        });
    }

    public function activate(User $actor, Membership $membership, string $reason): void
    {
        if (! $this->authorization->allows($actor, PermissionKey::MembershipManage, ResourceScope::tenant())) {
            throw new AuthorizationException('Membership activation is not permitted.');
        }

        $before = ['status' => $membership->status->value];
        $membership->forceFill([
            'status' => MembershipStatus::Active,
            'joined_at' => $membership->joined_at ?? now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            action: 'identity.membership.activated',
            targetType: 'membership',
            targetId: (string) $membership->getKey(),
            result: AuditResult::Succeeded,
            reason: $reason,
            before: $before,
            after: ['status' => $membership->status->value],
        ));
    }
}
