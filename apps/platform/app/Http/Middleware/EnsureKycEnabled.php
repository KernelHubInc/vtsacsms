<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Application\Kyc\KycException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureKycEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('kyc.enabled') && ! $request->routeIs('api.v1.kyc.status')) {
            throw new KycException('KYC_DISABLED', 404);
        }

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
