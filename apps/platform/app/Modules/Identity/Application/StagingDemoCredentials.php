<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Foundation\Demo\DemoEnvironment;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class StagingDemoCredentials
{
    /** @return list<array{public_id: string, old_email: string, email: string}> */
    public function accounts(): array
    {
        $logins = ['superadmin', 'admin', 'auditor', 'operator.admin', 'operator.ops', 'sitehost',
            'site.manager', 'requester', 'approver', 'procurement', 'inventory.manager', 'warehouse',
            'maintenance.manager', 'technician', 'finance', 'support', 'fleet.manager', 'executive', 'driver'];

        return array_map(function (string $login): array {
            $old = $login.'@demo.vsta.local';
            // Match the original seed's stable public IDs so no real customer can be selected by email alone.
            $bytes = hash('sha256', 'user:'.$old, true);
            $suffix = '';
            for ($i = 0; $i < 16; $i++) {
                $suffix .= '0123456789ABCDEFGHJKMNPQRSTVWXYZ'[ord($bytes[$i]) % 32];
            }

            return ['public_id' => '01J0000000'.$suffix, 'old_email' => $old, 'email' => $login.'@evcspowersolutions.com'];
        }, $logins);
    }

    public function rotate(#[\SensitiveParameter] string $password): int
    {
        if (! app()->environment(['staging', 'testing'])) {
            throw new LogicException('Demo credential rotation is restricted to staging.');
        }
        if (strlen($password) < 16 || strlen($password) > 72) {
            throw new InvalidArgumentException('Use a password between 16 and 72 characters.');
        }
        $context = new TenantContext(DemoEnvironment::TENANT_ID, ActorType::Service, null, (string) Str::ulid());

        return app(CurrentTenant::class)->run($context, fn (): int => DB::transaction(function () use ($password): int {
            $subjects = [];
            foreach ($this->accounts() as $account) {
                $user = User::query()->where('public_id', $account['public_id'])->lockForUpdate()->first();
                if ($user === null || ! in_array($user->email, [$account['old_email'], $account['email']], true)
                    || ! app(ChargingAccounts::class)->hasActiveMembership($user)) {
                    throw new LogicException('Seeded demo identity or tenant membership does not match. No accounts were changed.');
                }
                if (User::query()->whereRaw('lower(email) = ?', [$account['email']])->whereKeyNot($user->getKey())->exists()) {
                    throw new LogicException('A target email belongs to another account. No accounts were changed.');
                }
                $subjects[] = [$user, $account];
            }
            $hash = Hash::make($password);
            foreach ($subjects as [$user, $account]) {
                $old = $user->email;
                $user->forceFill(['email' => $account['email'], 'password' => $hash,
                    'security_version' => $user->security_version + 1, 'remember_token' => Str::random(60)])->save();
                $user->mobileTokens()->withoutGlobalScopes()->delete();
                $user->authSessions()->withoutGlobalScopes()->update(['revoked_at' => now('UTC')]);
                $user->apiTokens()->withoutGlobalScopes()->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                DB::table('password_reset_tokens')->whereIn('email', [$old, $account['email']])->delete();
                app(AuditRecorder::class)->record(new AuditEntry(
                    'identity.demo_credentials.rotated', 'identity', $user->public_id, AuditResult::Succeeded,
                    before: ['email' => $old], after: ['email' => $account['email']],
                    reason: 'Authorized staging demo account domain and credential change',
                ));
            }

            return count($subjects);
        }));
    }
}
