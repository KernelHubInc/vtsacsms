<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class ResetAccountPassword
{
    public function __construct(private CurrentTenant $tenant, private AuditRecorder $audit) {}

    public function reset(User $user, #[\SensitiveParameter] string $password, string $correlationId): void
    {
        DB::transaction(function () use ($user, $password, $correlationId): void {
            $subject = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $before = $subject->security_version;
            $subject->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'security_version' => $before + 1])->save();
            // Recovery has no tenant context: revoke this identity's sessions across all its memberships.
            $subject->mobileTokens()->withoutGlobalScopes()->delete();
            $subject->authSessions()->withoutGlobalScopes()->update(['revoked_at' => now('UTC')]);
            $subject->apiTokens()->withoutGlobalScopes()->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            DB::table('sessions')->where('user_id', $subject->getKey())->delete();
            foreach (DB::table('memberships')->where('user_id', $subject->getKey())->distinct()->pluck('tenant_id') as $tenantId) {
                $this->tenant->run(new TenantContext($tenantId, ActorType::Human, $subject->public_id, $correlationId), function () use ($subject, $before): void {
                    $this->audit->record(new AuditEntry('identity.password.reset', 'identity', $subject->public_id, AuditResult::Succeeded,
                        before: ['security_version' => $before], after: ['security_version' => $subject->security_version]));
                });
            }
            DB::afterCommit(fn () => event(new PasswordReset($subject)));
        });
    }
}
