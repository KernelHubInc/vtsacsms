<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PushRecipientQuery
{
    public function eligible(string $tenantId, string $tokenId): bool
    {
        return DB::table('personal_access_tokens as tokens')
            ->join('users', 'users.id', '=', 'tokens.tokenable_id')
            ->join('memberships', function ($join): void {
                $join->on('memberships.user_id', '=', 'users.id')->on('memberships.tenant_id', '=', 'tokens.tenant_id');
            })
            ->where('tokens.tenant_id', $tenantId)->where('tokens.id', $tokenId)
            ->where('tokens.tokenable_type', (new User)->getMorphClass())
            ->where('tokens.expires_at', '>', now())->whereColumn('tokens.subject_security_version', 'users.security_version')
            ->whereNotNull('users.activated_at')->whereNull('users.disabled_at')->whereNotNull('users.email_verified_at')
            ->where('memberships.status', 'active')
            ->where(fn ($query) => $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now()))
            ->exists();
    }
}
