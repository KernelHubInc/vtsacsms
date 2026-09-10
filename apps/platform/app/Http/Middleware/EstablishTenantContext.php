<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Application\AuthenticatedApiPrincipal;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class EstablishTenantContext
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentTenant->clear();
        $principal = $request->attributes->get('api_principal');

        if (! $principal instanceof AuthenticatedApiPrincipal || $principal->user->public_id === null) {
            return $this->notFound($request);
        }

        $selectedTenant = strtoupper(trim((string) $request->header('X-Tenant-ID')));

        if (
            $selectedTenant !== ''
            && ! hash_equals(strtoupper($principal->token->tenant_id), $selectedTenant)
        ) {
            Log::warning('tenant_selection_denied', [
                'selected_tenant_id' => $selectedTenant,
                'path' => $request->path(),
            ]);

            return $this->notFound($request);
        }

        $context = new TenantContext(
            tenantId: $principal->token->tenant_id,
            actorType: ActorType::Human,
            actorId: $principal->user->public_id,
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

    private function notFound(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'resource_not_found',
                'message' => 'The requested resource was not found.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ],
        ], 404);
    }
}
