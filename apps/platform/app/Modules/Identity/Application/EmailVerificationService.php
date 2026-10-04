<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\Queries\ActiveMembershipTenants;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

final readonly class EmailVerificationService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditRecorder $audit,
        private ActiveMembershipTenants $memberships,
    ) {}

    public function verify(string $publicId, string $hash, string $correlationId): bool
    {
        return DB::transaction(function () use ($publicId, $hash, $correlationId): bool {
            $user = User::query()->where('public_id', $publicId)->lockForUpdate()->first();

            if ($user === null || ! $user->isEnabled()
                || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return false;
            }

            // Email is global; audit each active membership's tenant without trusting request scope.
            $tenantIds = $this->memberships->forUser($user->getKey());

            if ($tenantIds === []) {
                return false;
            }

            if ($user->hasVerifiedEmail()) {
                return true;
            }

            $user->markEmailAsVerified();

            foreach ($tenantIds as $tenantId) {
                $this->currentTenant->run(new TenantContext(
                    tenantId: (string) $tenantId,
                    actorType: ActorType::Human,
                    actorId: $publicId,
                    correlationId: $correlationId,
                ), fn () => $this->audit->record(new AuditEntry(
                    action: 'identity.email.verified',
                    targetType: 'identity',
                    targetId: $publicId,
                    result: AuditResult::Succeeded,
                    before: ['email_verified_at' => null],
                    after: ['email_verified_at' => $user->email_verified_at?->toIso8601String()],
                )));
            }

            DB::afterCommit(static fn () => event(new Verified($user)));

            return true;
        });
    }
}
