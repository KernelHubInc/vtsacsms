<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AccountLifecycleService
{
    public function __construct(
        private MobileTokenService $tokens,
        private AuditRecorder $audit,
        private AuthorizationService $authorization,
    ) {}

    public function activate(User $actor, User $subject): void
    {
        $this->authorize($actor);
        $before = ['activated_at' => $subject->activated_at?->toIso8601String()];
        $subject->forceFill(['activated_at' => now('UTC'), 'disabled_at' => null])->save();

        $this->audit->record(new AuditEntry(
            action: 'identity.account.activated',
            targetType: 'identity',
            targetId: $subject->public_id,
            result: AuditResult::Succeeded,
            before: $before,
            after: ['activated_at' => $subject->activated_at?->toIso8601String()],
            metadata: ['actor_user_id' => $actor->public_id],
        ));
    }

    public function suspend(User $actor, User $subject, string $reason): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $subject, $reason): void {
            $before = [
                'disabled_at' => $subject->disabled_at?->toIso8601String(),
                'security_version' => $subject->security_version,
            ];
            $subject->forceFill(['disabled_at' => now('UTC')])->save();
            $this->tokens->revokeAll($actor, $subject, $reason);

            $this->audit->record(new AuditEntry(
                action: 'identity.account.suspended',
                targetType: 'identity',
                targetId: $subject->public_id,
                result: AuditResult::Succeeded,
                reason: $reason,
                before: $before,
                after: [
                    'disabled_at' => $subject->disabled_at?->toIso8601String(),
                    'security_version' => $subject->security_version,
                ],
                metadata: ['actor_user_id' => $actor->public_id],
            ));
        });
    }

    private function authorize(User $actor): void
    {
        if (! $this->authorization->allows(
            $actor,
            PermissionKey::MembershipManage,
            ResourceScope::tenant(),
        )) {
            throw new AuthorizationException('Global account lifecycle management is not permitted.');
        }
    }
}
