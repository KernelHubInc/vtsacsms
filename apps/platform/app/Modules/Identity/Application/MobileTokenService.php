<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

final readonly class MobileTokenService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<PermissionKey>  $abilities
     */
    public function issue(
        User $user,
        string $deviceName,
        array $abilities,
        ?CarbonImmutable $expiresAt = null,
    ): NewAccessToken {
        $tenantId = $this->currentTenant->get()->tenantId;
        $plainTextToken = $user->generateTokenString();
        $token = $user->mobileTokens()->create([
            'tenant_id' => $tenantId,
            'device_id' => (string) Str::ulid(),
            'name' => $deviceName,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => array_map(
                static fn (PermissionKey $permission): string => $permission->value,
                $abilities,
            ),
            'subject_security_version' => $user->security_version,
            'expires_at' => $expiresAt ?? CarbonImmutable::now('UTC')->addDays(30),
        ]);

        $this->audit->record(new AuditEntry(
            action: 'identity.mobile_token.issued',
            targetType: 'mobile_access_token',
            targetId: (string) $token->getKey(),
            result: AuditResult::Succeeded,
            after: [
                'device_id' => $token->device_id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at->utc()->toIso8601String(),
            ],
        ));

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    public function revoke(User $actor, MobileAccessToken $token, string $reason): void
    {
        $before = [
            'device_id' => $token->device_id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->utc()->toIso8601String(),
        ];
        $tokenId = (string) $token->getKey();
        $token->delete();

        $this->audit->record(new AuditEntry(
            action: 'identity.mobile_token.revoked',
            targetType: 'mobile_access_token',
            targetId: $tokenId,
            result: AuditResult::Succeeded,
            reason: $reason,
            before: $before,
            after: null,
            metadata: ['actor_user_id' => $actor->public_id],
        ));
    }

    public function revokeAll(User $actor, User $subject, string $reason): void
    {
        $beforeVersion = $subject->security_version;
        $subject->forceFill(['security_version' => $beforeVersion + 1])->save();
        $subject->mobileTokens()->delete();
        $subject->authSessions()->update(['revoked_at' => now('UTC')]);

        $this->audit->record(new AuditEntry(
            action: 'identity.sessions.revoked_all',
            targetType: 'identity',
            targetId: $subject->public_id,
            result: AuditResult::Succeeded,
            reason: $reason,
            before: ['security_version' => $beforeVersion],
            after: ['security_version' => $subject->security_version],
            metadata: ['actor_user_id' => $actor->public_id],
        ));
    }
}
