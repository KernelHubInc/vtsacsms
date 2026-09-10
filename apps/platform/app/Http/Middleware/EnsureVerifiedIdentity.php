<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureVerifiedIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            return response()->json(['error' => [
                'code' => 'email_unverified',
                'message' => 'Email verification is required.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        return $next($request);
    }
}
