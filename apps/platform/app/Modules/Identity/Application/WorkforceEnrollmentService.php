<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\WorkforceEnrollment;
use App\Modules\Identity\Notifications\WorkforceEnrollmentNotification;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\RegistrationTenantQuery;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class WorkforceEnrollmentService
{
    public function __construct(private CurrentTenant $tenant, private AuditRecorder $audit, private RegistrationTenantQuery $tenants, private AuthorizationService $authorization) {}

    /** @return array{user_id:int,subject_id:string} Credential-free projection for the existing membership foreign key. */
    public function prepare(string $email, string $planHash, bool $sendSetupEmail = true): array
    {
        $this->assertTrustedBootstrap();
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Bootstrap requires a new identity; existing accounts require a separate authorized workflow.']);
        }
        // The owner chooses the only usable password through the one-time enrollment page.
        $user = User::query()->create(['name' => $email, 'email' => $email, 'password' => Str::random(64), 'activated_at' => null]);
        $enrollment = WorkforceEnrollment::query()->create([
            'subject_id' => $user->public_id,
            'email_hash' => hash('sha256', $email),
            'plan_hash' => $planHash,
            'token_hash' => null,
            'expires_at' => now('UTC'),
        ]);
        if ($sendSetupEmail) {
            $this->issue($enrollment, $user);
        }

        return ['user_id' => (int) $user->getKey(), 'subject_id' => (string) $user->public_id];
    }

    /** @param list<string> $subjects */
    public function resend(array $subjects, string $planHash): void
    {
        $this->assertTrustedBootstrap();
        foreach ($subjects as $subjectId) {
            $enrollment = WorkforceEnrollment::query()->where('subject_id', $subjectId)->where('plan_hash', $planHash)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('public_id', $subjectId)->lockForUpdate()->firstOrFail();
            if ($enrollment->completed_at === null && $user->activated_at === null && $user->disabled_at === null
                && hash_equals($enrollment->email_hash, hash('sha256', Str::lower(trim($user->email))))) {
                $this->issue($enrollment, $user);
            }
        }
    }

    public function issueTemporaryPassword(string $email, string $approvalReference): string
    {
        $context = $this->tenant->get();
        if ($context->actorType !== ActorType::Platform || ! $this->tenants->isActive($context->tenantId)) {
            throw new AuthorizationException('Issuing a temporary password requires a trusted platform operation in an active tenant.');
        }
        if (! app()->environment('testing') && parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['delivery' => 'Temporary password enrollment requires an HTTPS application URL.']);
        }

        return DB::transaction(function () use ($email, $approvalReference): string {
            $emailHash = hash('sha256', Str::lower(trim($email)));
            $enrollment = WorkforceEnrollment::query()->where('email_hash', $emailHash)->lockForUpdate()->first();
            if ($enrollment === null || $enrollment->completed_at !== null) {
                $this->invalid();
            }
            $user = User::query()->where('public_id', $enrollment->subject_id)->lockForUpdate()->firstOrFail();
            if ($user->activated_at !== null || $user->disabled_at !== null
                || ! hash_equals($emailHash, hash('sha256', Str::lower(trim($user->email))))) {
                $this->invalid();
            }

            $temporaryPassword = 'CSMS-'.bin2hex(random_bytes(16));
            $enrollment->update(['token_hash' => hash('sha256', $temporaryPassword), 'expires_at' => now('UTC')->addDay(), 'email_sent_at' => null]);
            $this->audit->record(new AuditEntry('identity.workforce_enrollment.temporary_password_issued', 'workforce_enrollment', (string) $enrollment->getKey(), AuditResult::Succeeded,
                reason: $approvalReference, after: ['subject_id' => $user->public_id, 'expires_at' => $enrollment->expires_at->toIso8601String()]));

            return $temporaryPassword;
        });
    }

    public function complete(#[\SensitiveParameter] string $token, string $name, #[\SensitiveParameter] string $password, string $correlationId, ?string $email = null): void
    {
        // This is a pre-authentication credential lookup. Tenant scope comes only from the stored hash.
        $enrollment = WorkforceEnrollment::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
        if ($enrollment === null || ! $this->tenants->isActive($enrollment->tenant_id)) {
            $this->invalid();
        }
        $context = new TenantContext($enrollment->tenant_id, ActorType::Human, $enrollment->subject_id, $correlationId);
        $this->tenant->run($context, function () use ($enrollment, $token, $name, $password, $email): void {
            DB::transaction(function () use ($enrollment, $token, $name, $password, $email): void {
                $enrollment = WorkforceEnrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();
                $user = User::query()->where('public_id', $enrollment->subject_id)->lockForUpdate()->firstOrFail();
                $manual = str_starts_with($token, 'CSMS-');
                if (! is_string($enrollment->token_hash) || ! hash_equals($enrollment->token_hash, hash('sha256', $token))
                    || $enrollment->completed_at !== null || ! $enrollment->expires_at->isFuture()
                    || $user->activated_at !== null || $user->disabled_at !== null
                    || ($manual && ($email === null || ! hash_equals($enrollment->email_hash, hash('sha256', Str::lower(trim($email))))))
                    || hash_equals($token, $password)
                    || ! hash_equals($enrollment->email_hash, hash('sha256', Str::lower(trim($user->email))))) {
                    $this->invalid();
                }
                $user->forceFill(['name' => $name, 'password' => $password, 'activated_at' => now('UTC')]);
                if (! $manual) {
                    $user->email_verified_at = now('UTC');
                }
                $user->save();
                if (! $this->authorization->allows($user, PermissionKey::PlatformPanelAccess)) {
                    $this->invalid();
                }
                $enrollment->update(['completed_at' => now('UTC'), 'token_hash' => null]);
                $this->audit->record(new AuditEntry('identity.workforce_enrollment.completed', 'identity', $user->public_id, AuditResult::Succeeded,
                    after: ['enrollment_id' => $enrollment->getKey(), 'activated' => true, 'email_verified' => $user->hasVerifiedEmail(), 'method' => $manual ? 'temporary_password' : 'email_link']));
            });
        });
    }

    public function canSend(string $tenantId, string $enrollmentId, #[\SensitiveParameter] string $token): bool
    {
        return WorkforceEnrollment::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($enrollmentId)
            ->where('token_hash', hash('sha256', $token))->whereNull('completed_at')->where('expires_at', '>', now('UTC'))->exists();
    }

    public function delivered(string $tenantId, string $enrollmentId, #[\SensitiveParameter] string $token): void
    {
        WorkforceEnrollment::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($enrollmentId)
            ->where('token_hash', hash('sha256', $token))->whereNull('completed_at')->update(['email_sent_at' => now('UTC')]);
    }

    private function issue(WorkforceEnrollment $enrollment, User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $enrollment->update(['token_hash' => hash('sha256', $token), 'expires_at' => now('UTC')->addDay(), 'email_sent_at' => null]);
        $this->audit->record(new AuditEntry('identity.workforce_enrollment.issued', 'workforce_enrollment', (string) $enrollment->getKey(), AuditResult::Succeeded,
            after: ['subject_id' => $user->public_id, 'email_hash' => hash('sha256', $user->email), 'expires_at' => $enrollment->expires_at->toIso8601String()]));
        DB::afterCommit(fn () => Notification::route('mail', $user->email)->notify(new WorkforceEnrollmentNotification(
            $enrollment->tenant_id, (string) $enrollment->getKey(), $token,
        )));
    }

    private function assertTrustedBootstrap(): void
    {
        if ($this->tenant->get()->actorType !== ActorType::Platform || DB::transactionLevel() < 1) {
            throw new AuthorizationException('Workforce enrollment preparation requires an audited platform bootstrap transaction.');
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['enrollment_token' => 'This setup credential is unavailable. Ask the administrator for a new one.']);
    }
}
