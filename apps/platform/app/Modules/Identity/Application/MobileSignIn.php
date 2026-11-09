<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\Contracts\MfaChallengeProvider;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\Queries\ActiveMembershipTenants;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Laravel\Sanctum\NewAccessToken;

final readonly class MobileSignIn
{
    public function __construct(
        private ActiveMembershipTenants $memberships,
        private CurrentTenant $current,
        private AuthorizationService $authorization,
        private MobileTokenService $tokens,
        private MfaChallengeProvider $mfa,
        private AuthenticationAudit $audit,
    ) {}

    public function authorize(User $user, string $tenantId, string $channel): void
    {
        if (! $user->isEnabled() || ! in_array($tenantId, $this->memberships->forUser((int) $user->getKey()), true)) {
            $this->audit->record($user, $tenantId, $channel, AuditResult::Failed);
            throw new GoogleSignInException('invalid_credentials', 'The supplied credentials are invalid.');
        }
        if ($user->mfa_required) {
            $this->audit->record($user, $tenantId, $channel, AuditResult::Denied);
            throw new GoogleSignInException(
                $this->mfa->isAvailableFor($user) ? 'mfa_challenge_required' : 'mfa_unavailable',
                'Multi-factor authentication is required for this account.', 409,
            );
        }
    }

    public function issue(User $user, string $tenantId, string $deviceName, string $channel = 'mobile'): NewAccessToken
    {
        $this->authorize($user, $tenantId, $channel);
        $context = new TenantContext($tenantId, ActorType::Human, (string) $user->public_id,
            (string) request()->attributes->get('correlation_id'));
        $issued = $this->current->run($context, function () use ($user, $deviceName): NewAccessToken {
            $abilities = array_values(array_filter(PermissionKey::cases(),
                fn (PermissionKey $permission): bool => $this->authorization->holdsAnywhere($user, $permission)));

            return $this->tokens->issue($user, $deviceName, $abilities);
        });
        $this->audit->record($user, $tenantId, $channel, AuditResult::Succeeded);

        return $issued;
    }
}
