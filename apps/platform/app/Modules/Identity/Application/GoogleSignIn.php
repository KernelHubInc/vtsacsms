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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final readonly class GoogleSignIn
{
    public function __construct(private MobileSignIn $sessions, private AuthenticationAudit $audit, private CurrentTenant $current) {}

    /** @return array{user: User, token: NewAccessToken}|null */
    public function login(GoogleIdentity $identity, string $tenantId, string $deviceName, #[\SensitiveParameter] ?string $password): ?array
    {
        try {
            return DB::transaction(function () use ($identity, $tenantId, $deviceName, $password): ?array {
                $user = User::query()->where('google_subject', $identity->subject)->lockForUpdate()->first();
                if ($user === null) {
                    $user = User::query()->where('email', $identity->email)->lockForUpdate()->first();
                    if ($user === null) {
                        return null;
                    }
                    $this->sessions->authorize($user, $tenantId, 'google');
                    if ($user->google_subject !== null) {
                        throw new GoogleSignInException('invalid_credentials', 'This account could not be signed in. Use your existing sign-in method.');
                    }
                    if ($password === null) {
                        throw new GoogleSignInException('google_link_required', 'Enter your existing Power Solutions password to connect this Google account.', 409);
                    }
                    if (! Hash::check($password, $user->password)) {
                        $this->audit->record($user, $tenantId, 'google', AuditResult::Failed);
                        throw new GoogleSignInException('invalid_credentials', 'The supplied credentials are invalid.');
                    }
                    $user->forceFill(['google_subject' => $identity->subject])->save();
                    $this->current->run(new TenantContext($tenantId, ActorType::Human, (string) $user->public_id,
                        (string) request()->attributes->get('correlation_id')),
                        fn () => app(AuditRecorder::class)->record(new AuditEntry('identity.google.linked', 'identity', $user->public_id, AuditResult::Succeeded)));
                }

                return ['user' => $user, 'token' => $this->sessions->issue($user, $tenantId, $deviceName, 'google')];
            });
        } catch (GoogleSignInException $exception) {
            // Persist denied authentication after the account-linking transaction has rolled back.
            $user = User::query()->where('google_subject', $identity->subject)->orWhere('email', $identity->email)->first();
            $this->audit->record($user, $tenantId, 'google', AuditResult::Failed);
            throw $exception;
        } catch (UniqueConstraintViolationException) {
            throw new GoogleSignInException('google_account_changed', 'Your account changed during sign-in. Choose your Google account again.', 409);
        }
    }
}
