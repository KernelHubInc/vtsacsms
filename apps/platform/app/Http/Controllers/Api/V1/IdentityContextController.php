<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\AuthenticatedApiPrincipal;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IdentityContextController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly AuthorizationService $authorization,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $principal = $request->attributes->get('api_principal');
        $user = $request->user();

        abort_unless($principal instanceof AuthenticatedApiPrincipal && $user instanceof User, 401);

        return response()->json([
            'data' => [
                'subject_id' => $user->public_id,
                'tenant_id' => $this->currentTenant->get()->tenantId,
                'token_id' => $principal->token->getKey(),
                'token_abilities' => $principal->token->abilities,
                'effective_permissions' => $this->authorization->effectivePermissions($user),
            ],
        ]);
    }
}
