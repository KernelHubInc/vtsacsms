<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Application\BrowserSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class TrackPanelSession
{
    public function __construct(private BrowserSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $session = $this->sessions->track($user, $request);

        if ($session->revoked_at !== null) {
            auth()->logout();
            $request->session()->invalidate();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
