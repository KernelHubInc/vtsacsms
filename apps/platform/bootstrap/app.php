<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestContext;
use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Payments\Application\Qr\WalletException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestContext::class);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['ocpp_password']);
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if (! $request->routeIs('api.v1.auth.email.verify')) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['error' => [
                    'code' => 'invalid_verification_link',
                    'message' => 'The verification link is invalid or has expired.',
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ]], 403);
            }

            return response()->view('auth.email-verification', ['verified' => false], 403)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->is('api/v1/kyc/*', 'api/v1/webhooks/kyc') && $response->getStatusCode() >= 400) {
                $original = json_decode((string) $response->getContent(), true);
                if (! $exception instanceof KycException) {
                    $status = $response->getStatusCode();
                    $retryAfter = $response->headers->get('Retry-After');
                    $response = response()->json(['error' => [
                        'code' => match ($status) {
                            401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'VERIFICATION_NOT_FOUND',
                            413 => 'FILE_TOO_LARGE', 422 => 'INVALID_REQUEST', 429 => 'RATE_LIMITED',
                            default => 'KYC_SERVICE_UNAVAILABLE',
                        },
                        'message' => 'The identity verification request could not be completed.',
                        'request_id' => $request->attributes->get('request_id'),
                        'correlation_id' => $request->attributes->get('correlation_id'),
                        'fields' => $status === 422 ? ($original['errors'] ?? []) : [],
                    ]], $status)->header('Cache-Control', 'no-store');
                    if ($status === 429 && $retryAfter !== null) {
                        $response->headers->set('Retry-After', $retryAfter);
                    }
                }
            }
            if ($request->is('api/v1/wallet', 'api/v1/wallet/*', 'api/v1/webhooks/aub/qrph') && $response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                $original = json_decode((string) $response->getContent(), true);
                $retryAfter = $response->headers->get('Retry-After');
                $known = $exception instanceof WalletException;
                $kyc = $exception instanceof KycException;
                $response = response()->json(['error' => [
                    'code' => $known ? $exception->errorCode : ($kyc ? 'KYC_REQUIRED' : match ($status) {
                        401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'WALLET_RESOURCE_NOT_FOUND',
                        422 => 'INVALID_REQUEST', 429 => 'RATE_LIMITED', default => 'WALLET_UNAVAILABLE',
                    }),
                    'message' => $known ? $exception->getMessage() : ($kyc ? 'Complete identity verification before adding funds.' : 'The wallet request could not be completed.'),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                    'fields' => $status === 422 ? ($original['errors'] ?? []) : [],
                ]], $status)->header('Cache-Control', 'no-store');
                if ($retryAfter !== null) {
                    $response->headers->set('Retry-After', $retryAfter);
                }
            }
            if ($request->attributes->has('request_id')) {
                $response->headers->set('X-Request-ID', (string) $request->attributes->get('request_id'));
                $response->headers->set(
                    'X-Correlation-ID',
                    (string) $request->attributes->get('correlation_id'),
                );
            }

            return $response;
        });
    })->create();
