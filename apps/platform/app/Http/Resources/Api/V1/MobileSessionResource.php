<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\NewAccessToken;

final class MobileSessionResource
{
    public static function response(User $user, string $tenantId, NewAccessToken $issued, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'user' => new UserResource($user),
            'tenant_id' => $tenantId,
            'token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            'device_id' => $issued->accessToken->device_id,
            'expires_at' => $issued->accessToken->expires_at->utc()->toIso8601String(),
        ]], $status)->header('Cache-Control', 'no-store');
    }
}
