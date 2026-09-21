<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

final readonly class EmailVerificationService
{
    public function __construct(private CurrentTenant $currentTenant, private AuditRecorder $audit) {}

    public function verify(string $publicId, string $hash, string $correlationId): bool
    {
        return DB::transaction(function () use ($publicId, $hash, $correlationId): bool {
            $user = User::query()->where('public_id', $publicId)->lockForUpdate()->first();
            if (! $user instanceof User || ! $user->isEnabled()
                || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return false;
            }

            // Email belongs to the global identity; record evidence in an active membership's tenant.
            $tenantId = DB::table('memberships')
                ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
                ->where('memberships.user_id', $user->getKey())
                ->where('memberships.status', MembershipStatus::Active->value)
                ->where('tenants.status', TenantStatus::Active->value)
                ->where(function ($query): void {
                    $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
                })
                ->orderBy('memberships.tenant_id')->value('memberships.tenant_id');

            if (! is_string($tenantId)) {
                return false;
            }

            $context = new TenantContext(
                tenantId: $tenantId, actorType: ActorType::Human,
                actorId: $publicId, correlationId: $correlationId,
            );

            return $this->currentTenant->run($context, function () use ($user): bool {
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                    $this->audit->record(new AuditEntry(
                        action: 'identity.email.verified', targetType: 'identity',
                        targetId: $user->public_id, result: AuditResult::Succeeded,
                        before: ['email_verified_at' => null],
                        after: ['email_verified_at' => now('UTC')->toIso8601String()],
                    ));
                    DB::afterCommit(static fn () => event(new Verified($user)));
                }

                return true;
            });
        });
    }
}
