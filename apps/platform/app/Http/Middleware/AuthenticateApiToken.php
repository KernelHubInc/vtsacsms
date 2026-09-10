<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Application\ApiTokenAuthenticator;
use App\Modules\Identity\Application\Exceptions\InvalidApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateApiToken
{
    public function __construct(private ApiTokenAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken === null) {
            return $this->unauthenticated($request);
        }

        try {
            $principal = $this->authenticator->authenticate($plainTextToken);
        } catch (InvalidApiToken $exception) {
            Log::warning('api_authentication_denied', [
                'path' => $request->path(),
                'reason' => $exception->reason,
            ]);

            return $this->unauthenticated($request);
        }

        $request->attributes->set('api_principal', $principal);
        $request->setUserResolver(fn () => $principal->user);

        return $next($request);
    }

    private function unauthenticated(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Authentication is required.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ],
        ], 401);
    }
}
