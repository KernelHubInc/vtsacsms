<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class EstablishSanctumTenantContext
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentTenant->clear();
        $token = MobileAccessToken::findToken((string) $request->bearerToken());
        $user = $token?->tokenable;

        if ($user instanceof User) {
            $user->withAccessToken($token);
            $request->setUserResolver(static fn (): User => $user);
        }

        if (
            ! $user instanceof User
            || $user->public_id === null
            || ! $user->isEnabled()
            || $token->subject_security_version !== $user->security_version
            || $token->expires_at->isPast()
            || ! $this->hasActiveMembership($user, $token->tenant_id)
        ) {
            return $this->unauthenticated($request);
        }

        $selector = strtoupper(trim((string) $request->header('X-Tenant-ID')));

        if ($selector !== '' && ! hash_equals(strtoupper($token->tenant_id), $selector)) {
            return $this->notFound($request);
        }

        $context = new TenantContext(
            tenantId: $token->tenant_id,
            actorType: ActorType::Human,
            actorId: $user->public_id,
            correlationId: (string) $request->attributes->get('correlation_id'),
        );
        $this->currentTenant->establish($context);
        Log::shareContext(['tenant_id' => $context->tenantId]);

        try {
            return $next($request);
        } finally {
            $this->currentTenant->clear();
            Log::flushSharedContext();
            Log::shareContext([
                'request_id' => $request->attributes->get('request_id'),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);
        }
    }

    private function hasActiveMembership(User $user, string $tenantId): bool
    {
        return DB::table('memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.user_id', $user->getKey())
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('tenants.status', TenantStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('memberships.expires_at')
                    ->orWhere('memberships.expires_at', '>', now('UTC'));
            })->exists();
    }

    private function unauthenticated(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'unauthenticated',
            'message' => 'Authentication is required.',
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], 401);
    }

    private function notFound(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'resource_not_found',
            'message' => 'The requested resource was not found.',
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], 404);
    }
}
