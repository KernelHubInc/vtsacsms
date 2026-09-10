<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireSanctumPermission
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $permissionValue): Response
    {
        $permission = PermissionKey::tryFrom($permissionValue);
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (
            $permission === null
            || ! $user instanceof User
            || ! $token instanceof MobileAccessToken
            || ! $token->can($permission->value)
            || ! $this->authorization->holdsAnywhere($user, $permission)
        ) {
            return response()->json(['error' => [
                'code' => 'forbidden',
                'message' => 'You are not authorized to perform this action.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        return $next($request);
    }
}
