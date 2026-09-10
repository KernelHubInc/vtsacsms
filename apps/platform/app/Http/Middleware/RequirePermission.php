<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\AuthenticatedApiPrincipal;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RequirePermission
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionValue): Response
    {
        $permission = PermissionKey::tryFrom($permissionValue);
        $principal = $request->attributes->get('api_principal');
        $user = $request->user();
        $allowed = $permission !== null
            && $principal instanceof AuthenticatedApiPrincipal
            && $user instanceof User
            && $principal->token->hasAbility($permission)
            && $this->authorization->allows($user, $permission);

        if (! $allowed) {
            $this->recordDenial($request, $permissionValue);

            return $this->forbidden($request);
        }

        return $next($request);
    }

    private function recordDenial(Request $request, string $permission): void
    {
        try {
            $this->audit->record(new AuditEntry(
                action: 'security.authorization.denied',
                targetType: 'api_route',
                targetId: null,
                result: AuditResult::Denied,
                metadata: [
                    'permission' => $permission,
                    'method' => $request->method(),
                    'path' => $request->path(),
                ],
                sourceIp: $request->ip(),
            ));
        } catch (Throwable $exception) {
            Log::critical('authorization_denial_audit_failed', [
                'exception_class' => $exception::class,
                'permission' => $permission,
            ]);
        }
    }

    private function forbidden(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'You are not authorized to perform this action.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ],
        ], 403);
    }
}
