<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreChargingStationRequest;
use App\Http\Requests\Api\UpdateChargingStationRequest;
use App\Http\Resources\Api\V1\ChargingStationResource;
use App\Models\User;
use App\Modules\Assets\Application\AccessibleStationsQuery;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChargingStationController extends Controller
{
    public function index(Request $request, AccessibleStationsQuery $stations): AnonymousResourceCollection
    {
        return ChargingStationResource::collection($stations->for($this->user($request))->with(['site:id,name'])->cursorPaginate(50));
    }

    public function show(Request $request, string $station, AccessibleStationsQuery $stations): ChargingStationResource
    {
        return new ChargingStationResource($stations->for($this->user($request))->whereKey($station)->firstOrFail());
    }

    public function store(StoreChargingStationRequest $request, CurrentTenant $tenant, AuthorizationService $authorization, AuditRecorder $audit): ChargingStationResource
    {
        $site = Site::query()->whereKey($request->validated('site_id'))->firstOrFail();
        abort_unless($authorization->allows($this->user($request), PermissionKey::AssetManage, new ResourceScope(ScopeType::Site, (string) $site->getKey())), 403);
        $station = DB::transaction(function () use ($request, $tenant, $audit): ChargingStation {
            $station = ChargingStation::query()->create(['tenant_id' => $tenant->get()->tenantId, ...$request->validated(), 'lifecycle_status' => $request->validated('lifecycle_status', AssetLifecycleStatus::Draft->value)]);
            $audit->record(new AuditEntry('assets.station.created', 'charging_station', (string) $station->getKey(), AuditResult::Succeeded, after: $station->toArray()));

            return $station;
        });

        return new ChargingStationResource($station);
    }

    public function update(UpdateChargingStationRequest $request, string $station, AccessibleStationsQuery $stations, AuditRecorder $audit): ChargingStationResource
    {
        $model = $stations->for($this->user($request), PermissionKey::AssetManage)->whereKey($station)->firstOrFail();
        Gate::authorize('update', $model);
        DB::transaction(function () use ($model, $request, $audit): void {
            $before = $model->toArray();
            $model->update($request->validated());
            $audit->record(new AuditEntry('assets.station.updated', 'charging_station', (string) $model->getKey(), AuditResult::Succeeded, before: $before, after: $model->fresh()->toArray()));
        });

        return new ChargingStationResource($model->refresh());
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
