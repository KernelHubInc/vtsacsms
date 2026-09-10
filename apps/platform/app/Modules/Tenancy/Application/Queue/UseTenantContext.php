<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queue;

use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class UseTenantContext
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        $this->currentTenant->clear();
        $envelope = $job->tenantJobEnvelope();
        $tenantIsActive = Tenant::query()
            ->whereKey($envelope->tenantId)
            ->where('status', TenantStatus::Active->value)
            ->exists();

        if (! $tenantIsActive || ! $this->actorIsAuthorized($envelope)) {
            throw new InvalidTenantJobContext;
        }

        $this->currentTenant->establish($envelope->toTenantContext());
        Log::shareContext([
            'tenant_id' => $envelope->tenantId,
            'job_id' => $envelope->jobId,
            'correlation_id' => $envelope->correlationId,
        ]);

        try {
            return $next($job);
        } finally {
            $this->currentTenant->clear();
            Log::flushSharedContext();
        }
    }

    private function actorIsAuthorized(TenantJobEnvelope $envelope): bool
    {
        if ($envelope->actorType !== ActorType::Human || $envelope->actorId === null) {
            return false;
        }

        $userId = User::query()
            ->where('public_id', $envelope->actorId)
            ->whereNull('disabled_at')
            ->value('id');

        if ($userId === null) {
            return false;
        }

        return DB::table('memberships')
            ->where('tenant_id', $envelope->tenantId)
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC'));
            })
            ->exists();
    }
}
