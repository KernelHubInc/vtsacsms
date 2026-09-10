<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Modules\Identity\Application\Contracts\MfaChallengeProvider;
use App\Modules\Identity\Application\MobileTokenService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class MobileAuthController extends Controller
{
    public function login(
        LoginRequest $request,
        CurrentTenant $currentTenant,
        AuthorizationService $authorization,
        MobileTokenService $tokens,
        MfaChallengeProvider $mfa,
    ): JsonResponse {
        $email = mb_strtolower((string) $request->validated('email'));
        $user = User::query()->where('email', $email)->first();
        $tenantId = (string) $request->validated('tenant_id');

        if (
            $user === null
            || ! Hash::check((string) $request->validated('password'), $user->password)
            || ! $user->isEnabled()
            || ! $this->hasActiveMembership($user, $tenantId)
        ) {
            return $this->invalidCredentials($request);
        }

        if ($user->mfa_required) {
            return response()->json(['error' => [
                'code' => $mfa->isAvailableFor($user) ? 'mfa_challenge_required' : 'mfa_unavailable',
                'message' => 'Multi-factor authentication is required for this account.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 409);
        }

        $context = new TenantContext(
            tenantId: $tenantId,
            actorType: ActorType::Human,
            actorId: (string) $user->public_id,
            correlationId: (string) $request->attributes->get('correlation_id'),
        );
        $issued = $currentTenant->run($context, function () use (
            $user,
            $request,
            $authorization,
            $tokens,
        ) {
            $abilities = array_values(array_filter(
                PermissionKey::cases(),
                static fn (PermissionKey $permission): bool => $authorization->holdsAnywhere($user, $permission),
            ));

            return $tokens->issue(
                $user,
                (string) $request->validated('device_name'),
                $abilities,
            );
        });

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantId,
                'token' => $issued->plainTextToken,
                'token_type' => 'Bearer',
                'device_id' => $issued->accessToken->device_id,
                'expires_at' => $issued->accessToken->expires_at->utc()->toIso8601String(),
            ],
        ]);
    }

    public function logout(Request $request, MobileTokenService $tokens): JsonResponse
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($user instanceof User && $token instanceof MobileAccessToken) {
            $tokens->revoke($user, $token, 'user_logout');
        }

        return response()->json([], 204);
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
                $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
            })->exists();
    }

    private function invalidCredentials(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'invalid_credentials',
            'message' => 'The supplied credentials are invalid.',
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], 401);
    }
}
