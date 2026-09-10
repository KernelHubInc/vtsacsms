<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RemoteStartRequest;
use App\Http\Resources\Api\V1\ChargerCommandResource;
use App\Http\Resources\Api\V1\ChargingSessionResource;
use App\Models\User;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Charging\Application\RemoteStartService;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use DomainException;
use Illuminate\Http\JsonResponse;

final class RemoteStartController extends Controller
{
    public function __invoke(
        RemoteStartRequest $request,
        ConnectorSnapshotQuery $connectors,
        AuthorizationService $authorization,
        RemoteStartService $starts,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $connector = $connectors->byId((string) $request->validated('connector_id'));
        abort_if($connector === null, 404);
        abort_unless($authorization->allows(
            $user,
            PermissionKey::ChargingRemoteCommand,
            new ResourceScope(ScopeType::Site, $connector->siteId),
        ), 403);

        try {
            $result = $starts->request(
                $connector->connectorId,
                (string) $request->validated('idempotency_key'),
                $request->validated('tariff_version_id'),
                $request->validated('promotion_code'),
            );
        } catch (DomainException $exception) {
            return $this->unprocessable($request, $exception);
        }

        return response()->json(['data' => [
            'session' => (new ChargingSessionResource($result->session->refresh()))->resolve($request),
            'command' => (new ChargerCommandResource($result->command->refresh()))->resolve($request),
        ]], 202);
    }

    private function unprocessable(RemoteStartRequest $request, DomainException $exception): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'charging_start_rejected',
            'message' => $exception->getMessage(),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], 422);
    }
}
