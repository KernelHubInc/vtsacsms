<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Application\Exceptions\InvalidApiToken;
use App\Modules\Identity\Domain\Models\ApiToken;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

final class ApiTokenAuthenticator
{
    private const string TOKEN_PATTERN = '/\Avtsa_([0-9A-HJKMNP-TV-Z]{26})_([A-Za-z0-9]{64})\z/';

    public function authenticate(string $plainTextToken): AuthenticatedApiPrincipal
    {
        if (preg_match(self::TOKEN_PATTERN, $plainTextToken, $matches) !== 1) {
            throw new InvalidApiToken('malformed');
        }

        $tokenId = strtoupper($matches[1]);

        if (! Ulid::isValid($tokenId)) {
            throw new InvalidApiToken('malformed_identifier');
        }

        $token = ApiToken::withoutGlobalScopes()->find($tokenId);

        if ($token === null) {
            throw new InvalidApiToken('not_found');
        }

        if (! hash_equals($token->token_hash, hash('sha256', $plainTextToken))) {
            throw new InvalidApiToken('hash_mismatch');
        }

        if (! $token->isUsable()) {
            throw new InvalidApiToken('expired_or_revoked');
        }

        $user = User::query()->find($token->user_id);
        $tenantIsActive = Tenant::query()
            ->whereKey($token->tenant_id)
            ->where('status', TenantStatus::Active->value)
            ->exists();
        $membershipIsActive = DB::table('memberships')
            ->where('tenant_id', $token->tenant_id)
            ->where('user_id', $token->user_id)
            ->where('status', MembershipStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC'));
            })
            ->exists();

        if (
            $user === null
            || ! $user->isEnabled()
            || $token->subject_security_version !== $user->security_version
            || ! $tenantIsActive
            || ! $membershipIsActive
        ) {
            throw new InvalidApiToken('subject_or_tenant_inactive');
        }

        ApiToken::withoutGlobalScopes()
            ->where('tenant_id', $token->tenant_id)
            ->whereKey($token->getKey())
            ->update(['last_used_at' => now('UTC')]);

        return new AuthenticatedApiPrincipal($user, $token);
    }
}
