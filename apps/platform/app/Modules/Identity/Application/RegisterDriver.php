<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\ConsumerEnrollment;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final readonly class RegisterDriver
{
    public function __construct(private ConsumerEnrollment $enrollment, private CurrentTenant $current, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(#[\SensitiveParameter] array $data, ?GoogleIdentity $google = null): User
    {
        $tenantId = (string) $data['tenant_id'];
        $this->enrollment->scope($tenantId);

        return DB::transaction(function () use ($data, $tenantId, $google): User {
            $user = User::query()->create([
                'name' => $data['name'], 'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null, 'last_name' => $data['last_name'],
                'birth_date' => $data['birth_date'], 'email' => $google === null ? mb_strtolower((string) $data['email']) : $google->email,
                // A Google-only account can set a password later through verified recovery.
                'password' => $google === null ? $data['password'] : Str::random(64),
                'activated_at' => now('UTC'),
            ]);
            if ($google !== null) {
                $user->forceFill(['google_subject' => $google->subject,
                    'email_verified_at' => $google->emailAuthoritative ? now('UTC') : null])->save();
            }
            $this->enrollment->enroll((int) $user->getKey(), $tenantId);
            $this->current->run(new TenantContext($tenantId, ActorType::Human, (string) $user->public_id, (string) Str::ulid()),
                function () use ($user, $data, $google): void {
                    $this->audit->record(new AuditEntry('identity.user.registered', 'identity', $user->public_id,
                        AuditResult::Succeeded, metadata: ['method' => $google === null ? 'password' : 'google']));
                    if ($google !== null) {
                        $this->audit->record(new AuditEntry('identity.google.linked', 'identity', $user->public_id, AuditResult::Succeeded));
                    }
                    app(DriverVehicles::class)->save((string) $user->public_id, (string) Str::ulid(), [
                        'nickname' => 'My vehicle', 'plate_number' => $data['plate_number'] ?? null,
                        'plate_pending' => (bool) $data['plate_pending'], 'is_default' => true,
                    ]);
                });
            if ($user->hasVerifiedEmail()) {
                DB::afterCommit(static fn () => event(new Verified($user)));
            }

            return $user;
        });
    }

    public function sendVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (TransportExceptionInterface $exception) {
            Log::warning('identity.registration.verification_email_failed', [
                'tenant_id' => request()->input('tenant_id'),
                'correlation_id' => request()->attributes->get('correlation_id'),
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
