<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelChargingSessionRequest;
use App\Http\Resources\Api\V1\ChargingSessionResource;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Application\SessionLifecycleService;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ChargingSessionController extends Controller
{
    public function index(Request $request, AccessibleChargingSessionsQuery $sessions): AnonymousResourceCollection
    {
        return ChargingSessionResource::collection(
            $sessions->for($this->user($request))->latest('requested_at')->cursorPaginate(50),
        );
    }

    public function show(Request $request, string $session, AccessibleChargingSessionsQuery $sessions): ChargingSessionResource
    {
        $model = $sessions->for($this->user($request))
            ->with(['commands', 'chargeDetailRecords'])
            ->whereKey($session)
            ->firstOrFail();

        return new ChargingSessionResource($model);
    }

    public function cancel(
        CancelChargingSessionRequest $request,
        string $session,
        AccessibleChargingSessionsQuery $sessions,
        SessionLifecycleService $lifecycle,
    ): JsonResponse {
        $model = $sessions->for($this->user($request), PermissionKey::ChargingRemoteCommand)
            ->whereKey($session)
            ->firstOrFail();

        try {
            $lifecycle->cancel($model, (string) $request->validated('reason'));
        } catch (DomainException $exception) {
            return response()->json(['error' => [
                'code' => 'charging_cancellation_rejected',
                'message' => $exception->getMessage(),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 422);
        }

        return response()->json(['data' => (new ChargingSessionResource($model->refresh()))->resolve($request)]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
