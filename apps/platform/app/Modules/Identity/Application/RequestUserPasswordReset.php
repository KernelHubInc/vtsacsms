<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AccessibleMembershipsQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class RequestUserPasswordReset
{
    public function __construct(
        private AuthorizationService $authorization,
        private AccessibleMembershipsQuery $memberships,
        private AuditRecorder $audit,
    ) {}

    public function canRequest(User $actor): bool
    {
        return $this->authorization->allows($actor, PermissionKey::PlatformPanelAccess)
            && $this->authorization->allows($actor, PermissionKey::MembershipManage);
    }

    public function send(User $actor, User $subject): void
    {
        if (! $this->canRequest($actor) || ! $this->memberships->for($actor)->where('user_id', $subject->getKey())->exists()) {
            throw new AuthorizationException('Password recovery is not permitted for this account.');
        }
        if (! $subject->isEnabled()) {
            throw ValidationException::withMessages(['resetPassword' => 'Activate this account before requesting password recovery.']);
        }
        // Sending a recovery link never changes a password or revokes the user's sessions.
        // The existing recovery workflow performs both after the user chooses a password.
        try {
            $status = Password::sendResetLink(['email' => $subject->email]);
        } catch (Throwable) {
            $status = 'delivery_failed';
        }
        $sent = $status === Password::ResetLinkSent;
        $this->audit->record(new AuditEntry(
            'identity.password.recovery_requested', 'identity', $subject->public_id,
            $sent ? AuditResult::Succeeded : AuditResult::Failed,
            metadata: ['actor_user_id' => $actor->public_id, 'outcome' => $sent ? 'sent' : ($status === Password::ResetThrottled ? 'throttled' : 'delivery_failed')],
        ));
        if (! $sent) {
            throw ValidationException::withMessages(['resetPassword' => $status === Password::ResetThrottled
                ? 'A reset link was requested recently. Please wait before trying again.'
                : 'The reset email could not be sent. Check email delivery and try again.']);
        }
    }
}
