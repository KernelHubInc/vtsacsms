<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SanctumIdentityController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentTenant $currentTenant,
        AuthorizationService $authorization,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'tenant_id' => $currentTenant->get()->tenantId,
            'permissions' => array_values(array_filter(
                $authorization->effectivePermissions($user),
                static fn (string $permission): bool => $user->tokenCan($permission),
            )),
        ]]);
    }
}
