<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\ApiToken;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ApiTokenService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<PermissionKey>  $abilities
     */
    public function issue(
        User $actor,
        User $subject,
        string $name,
        array $abilities,
        CarbonImmutable $expiresAt,
        ?string $reason = null,
    ): IssuedApiToken {
        if (! $this->authorization->allows($actor, PermissionKey::TokenIssue)) {
            throw new AuthorizationException('API token issuance is not permitted.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120 || ! $expiresAt->isFuture()) {
            throw new InvalidArgumentException('Token name and future expiry are required.');
        }

        $tenantId = $this->currentTenant->get()->tenantId;
        $subjectHasMembership = DB::table('memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $subject->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC'));
            })
            ->exists();

        if (! $subject->isEnabled() || ! $subjectHasMembership) {
            throw new AuthorizationException('The token subject is not active in this tenant.');
        }

        $abilityValues = array_values(array_unique(array_map(
            static fn (PermissionKey $permission): string => $permission->value,
            $abilities,
        )));

        foreach ($abilities as $ability) {
            if (! $this->authorization->holdsAnywhere($subject, $ability)) {
                throw new AuthorizationException('A token cannot exceed its subject authority.');
            }
        }

        return DB::transaction(function () use (
            $actor,
            $subject,
            $name,
            $abilityValues,
            $expiresAt,
            $reason,
            $tenantId,
        ): IssuedApiToken {
            $tokenId = (string) Str::ulid();
            $plainTextToken = 'vtsa_'.$tokenId.'_'.Str::random(64);
            $token = new ApiToken([
                'tenant_id' => $tenantId,
                'user_id' => $subject->getKey(),
                'name' => $name,
                'token_hash' => hash('sha256', $plainTextToken),
                'abilities' => $abilityValues,
                'subject_security_version' => $subject->security_version,
                'expires_at' => $expiresAt,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $token->setAttribute('id', $tokenId);
            $token->save();

            $this->audit->record(new AuditEntry(
                action: 'identity.api_token.issued',
                targetType: 'api_token',
                targetId: $tokenId,
                result: AuditResult::Succeeded,
                reason: $reason,
                changes: [
                    'subject_id' => $subject->public_id,
                    'name' => $name,
                    'abilities' => $abilityValues,
                    'expires_at' => $expiresAt->utc()->toIso8601String(),
                ],
            ));

            return new IssuedApiToken($token, $plainTextToken);
        });
    }

    public function revoke(User $actor, ApiToken $token, string $reason): void
    {
        if (! $this->authorization->allows($actor, PermissionKey::TokenRevoke)) {
            throw new AuthorizationException('API token revocation is not permitted.');
        }

        if ($token->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($token, $reason): void {
            $token->forceFill(['revoked_at' => now('UTC')])->save();
            $this->audit->record(new AuditEntry(
                action: 'identity.api_token.revoked',
                targetType: 'api_token',
                targetId: (string) $token->getKey(),
                result: AuditResult::Succeeded,
                reason: $reason,
            ));
        });
    }

    public function revokeAllForSubject(User $actor, User $subject, string $reason): int
    {
        if (! $this->authorization->allows($actor, PermissionKey::TokenRevoke)) {
            throw new AuthorizationException('API token revocation is not permitted.');
        }

        return DB::transaction(function () use ($subject, $reason): int {
            $subject->forceFill(['security_version' => $subject->security_version + 1])->save();
            $count = ApiToken::query()
                ->where('user_id', $subject->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now('UTC')]);
            $this->audit->record(new AuditEntry(
                action: 'identity.subject_tokens.revoked',
                targetType: 'identity_subject',
                targetId: $subject->public_id,
                result: AuditResult::Succeeded,
                reason: $reason,
                changes: ['revoked_token_count' => $count],
            ));

            return $count;
        });
    }
}
