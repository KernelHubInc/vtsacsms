<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestContext;
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
