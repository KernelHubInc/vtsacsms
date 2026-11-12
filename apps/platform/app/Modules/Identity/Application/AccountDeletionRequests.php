<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Notifications\AccountDeletionCodeNotification;
use App\Modules\Organizations\Application\Queries\AccountRequestTenant;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AccountDeletionRequests
{
    public function __construct(
        private AccountRequestTenant $tenants,
        private CurrentTenant $current,
        private SupportTicketService $support,
    ) {}

    public function start(string $email, ?string $previousChallenge): string
    {
        if ($previousChallenge !== null) {
            Cache::forget($this->cacheKey($previousChallenge));
        }

        $challenge = Str::random(40);
        $user = User::query()->where('email', $email)->first();
        if ($user === null || $user->public_id === null || $this->tenants->forUser((int) $user->getKey()) === null) {
            return $challenge;
        }

        $code = (string) random_int(10000000, 99999999);
        $expiresAt = now()->addMinutes(15);
        Cache::put($this->cacheKey($challenge), [
            'subject' => $user->public_id,
            'email_digest' => $this->digest($challenge, (string) $user->email),
            'digest' => $this->digest($challenge, $code),
            'attempts' => 0,
            'expires' => $expiresAt->timestamp,
        ], $expiresAt);

        $user->notify(new AccountDeletionCodeNotification($challenge, $code));

        return $challenge;
    }

    public function isPending(string $challenge, #[\SensitiveParameter] string $code): bool
    {
        $record = Cache::get($this->cacheKey($challenge));

        return is_array($record) && $record['expires'] > now()->timestamp
            && hash_equals($record['digest'], $this->digest($challenge, $code));
    }

    public function confirm(string $challenge, #[\SensitiveParameter] string $code, string $correlationId): bool
    {
        $key = $this->cacheKey($challenge);
        try {
            return Cache::lock($key.':lock', 15)->block(3, function () use ($key, $challenge, $code, $correlationId): bool {
                $record = Cache::get($key);
                if (! is_array($record) || $record['expires'] <= now()->timestamp) {
                    return false;
                }

                if (! hash_equals($record['digest'], $this->digest($challenge, $code))) {
                    $record['attempts']++;
                    if ($record['attempts'] >= 5) {
                        Cache::forget($key);
                    } else {
                        Cache::put($key, $record, max(1, $record['expires'] - now()->timestamp));
                    }

                    return false;
                }

                $created = DB::transaction(function () use ($record, $challenge, $correlationId): bool {
                    $user = User::query()->where('public_id', $record['subject'])->lockForUpdate()->first();
                    $tenant = $user ? $this->tenants->forUser((int) $user->getKey()) : null;
                    if ($user === null || $tenant === null || ! hash_equals($record['email_digest'], $this->digest($challenge, (string) $user->email))) {
                        return false;
                    }

                    $context = new TenantContext($tenant, ActorType::Human, $user->public_id, $correlationId);
                    $this->current->run($context, fn () => $this->support->requestAccountDeletion($user));

                    return true;
                });
                Cache::forget($key);

                return $created;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function cacheKey(string $challenge): string
    {
        return 'identity:account-deletion:'.$challenge;
    }

    private function digest(string $challenge, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $challenge.'|'.$code, (string) config('app.key'));
    }
}
