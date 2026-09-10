<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
