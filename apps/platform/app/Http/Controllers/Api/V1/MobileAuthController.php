<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\Api\V1\MobileSessionResource;
use App\Models\User;
use App\Modules\Identity\Application\AuthenticationAudit;
use App\Modules\Identity\Application\MobileSignIn;
use App\Modules\Identity\Application\MobileTokenService;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class MobileAuthController extends Controller
{
    public function login(LoginRequest $request, MobileSignIn $sessions): JsonResponse
    {
        $user = User::query()->where('email', mb_strtolower((string) $request->validated('email')))->first();
        $tenantId = (string) $request->validated('tenant_id');
        if ($user === null || ! Hash::check((string) $request->validated('password'), $user->password)) {
            app(AuthenticationAudit::class)->record($user, $tenantId, 'mobile', AuditResult::Failed);

            return response()->json(['error' => [
                'code' => 'invalid_credentials', 'message' => 'The supplied credentials are invalid.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 401)->header('Cache-Control', 'no-store');
        }

        return MobileSessionResource::response($user, $tenantId,
            $sessions->issue($user, $tenantId, (string) $request->validated('device_name')));
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
}
