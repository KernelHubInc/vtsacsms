<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AssignRequestContext
{
    private const string ULID_PATTERN = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = $this->resolveId($request->header('X-Request-ID'));
        $correlationId = $this->resolveId($request->header('X-Correlation-ID'), $requestId);

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);
        Log::shareContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);
            $response->headers->set('X-Correlation-ID', $correlationId);

            Log::info('http_request_completed', [
                'http' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status_code' => $response->getStatusCode(),
                    'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
                ],
            ]);

            return $response;
        } catch (Throwable $exception) {
            Log::error('http_request_failed', [
                'http' => [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
                ],
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        } finally {
            Log::withoutContext(['request_id', 'correlation_id']);
            Log::flushSharedContext();
        }
    }

    private function resolveId(?string $candidate, ?string $fallback = null): string
    {
        $normalized = strtoupper(trim((string) $candidate));

        if (preg_match(self::ULID_PATTERN, $normalized) === 1) {
            return $normalized;
        }

        return $fallback ?? (string) Str::ulid();
    }
}
