<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RemoteStopRequest;
use App\Http\Resources\Api\V1\ChargerCommandResource;
use App\Http\Resources\Api\V1\ChargingSessionResource;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Application\RemoteStopService;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;
use Illuminate\Http\JsonResponse;

final class RemoteStopController extends Controller
{
    public function __invoke(
        RemoteStopRequest $request,
        string $session,
        AccessibleChargingSessionsQuery $sessions,
        RemoteStopService $stops,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $model = $sessions->for($user, PermissionKey::ChargingRemoteCommand)->whereKey($session)->firstOrFail();

        try {
            $result = $stops->request($model, (string) $request->validated('idempotency_key'));
        } catch (DomainException $exception) {
            return response()->json(['error' => [
                'code' => 'charging_stop_rejected',
                'message' => $exception->getMessage(),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 422);
        }

        return response()->json(['data' => [
            'session' => (new ChargingSessionResource($result->session->refresh()))->resolve($request),
            'command' => (new ChargerCommandResource($result->command->refresh()))->resolve($request),
        ]], 202);
    }
}
