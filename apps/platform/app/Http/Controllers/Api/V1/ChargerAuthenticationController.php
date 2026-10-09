<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AuthenticateChargerRequest;
use App\Http\Requests\Api\ResolveChargerRequest;
use App\Modules\Identity\Application\ChargerCredentials;
use App\Modules\Identity\Application\RegisteredChargers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class ChargerAuthenticationController extends Controller
{
    public function resolve(ResolveChargerRequest $request, RegisteredChargers $chargers): JsonResponse
    {
        $data = $request->validated();
        $binding = $chargers->resolve($data['identity'], $data['protocol']);
        if ($binding === null) {
            return $this->failure($request, 403);
        }

        return response()->json(['data' => [...$binding, 'authentication' => 'registered']])->header('Cache-Control', 'no-store');
    }

    public function __invoke(AuthenticateChargerRequest $request, ChargerCredentials $credentials): JsonResponse
    {
        $data = $request->validated();
        $key = 'ocpp-auth:'.hash('sha256', $data['identity']);
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return $this->failure($request, 429);
        }
        RateLimiter::hit($key, 60);
        $binding = $credentials->authenticate($data['identity'], $data['password'], $data['protocol']);
        if ($binding === null) {
            return $this->failure($request, 403);
        }

        return response()->json(['data' => $binding])->header('Cache-Control', 'no-store');
    }

    private function failure(Request $request, int $status): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'CHARGER_AUTHENTICATION_FAILED',
            'message' => 'Charger authentication could not be completed.',
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], $status)->header('Cache-Control', 'no-store');
    }
}
