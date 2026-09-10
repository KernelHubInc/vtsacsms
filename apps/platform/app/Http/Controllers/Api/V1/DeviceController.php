<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\User;
use App\Modules\Identity\Application\BrowserSessionService;
use App\Modules\Identity\Application\MobileTokenService;
use App\Modules\Identity\Domain\Models\AuthSession;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tenantId = $user->currentAccessToken()->tenant_id;
        $devices = $user->mobileTokens()
            ->where('tenant_id', $tenantId)
            ->latest('last_used_at')
            ->get()
            ->concat($user->authSessions()
                ->where('tenant_id', $tenantId)
                ->whereNull('revoked_at')
                ->latest('last_active_at')
                ->get());

        return DeviceResource::collection($devices);
    }

    public function destroy(
        Request $request,
        string $device,
        MobileTokenService $tokens,
        BrowserSessionService $sessions,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var MobileAccessToken|null $token */
        $token = $user->mobileTokens()
            ->where('tenant_id', $user->currentAccessToken()->tenant_id)
            ->where('device_id', $device)
            ->first();

        if ($token instanceof MobileAccessToken) {
            $tokens->revoke($user, $token, 'user_device_revocation');

            return response()->json([], 204);
        }

        /** @var AuthSession $session */
        $session = $user->authSessions()
            ->where('tenant_id', $user->currentAccessToken()->tenant_id)
            ->whereKey($device)
            ->firstOrFail();
        $sessions->revoke($user, $session, 'user_session_revocation');

        return response()->json([], 204);
    }

    public function destroyAll(Request $request, MobileTokenService $tokens): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tokens->revokeAll($user, $user, 'user_requested_global_revocation');

        return response()->json([], 204);
    }
}
