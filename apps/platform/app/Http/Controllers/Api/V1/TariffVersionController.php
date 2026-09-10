<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTariffVersionRequest;
use App\Http\Resources\Api\V1\TariffVersionResource;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class TariffVersionController extends Controller
{
    public function store(
        StoreTariffVersionRequest $request,
        string $tariff,
        TariffManagementService $tariffs,
    ): JsonResponse {
        $model = Tariff::query()->whereKey($tariff)->firstOrFail();
        Gate::authorize('update', $model);
        try {
            return (new TariffVersionResource($tariffs->addVersion($model, $request->validated())))
                ->response()->setStatusCode(201);
        } catch (DomainException $exception) {
            return $this->unprocessable($request, $exception);
        }
    }

    public function publish(
        Request $request,
        string $version,
        TariffManagementService $tariffs,
    ): TariffVersionResource|JsonResponse {
        $model = TariffVersion::query()->with('tariff')->whereKey($version)->firstOrFail();
        Gate::authorize('publish', $model->tariff);
        try {
            return new TariffVersionResource($tariffs->publish($model));
        } catch (DomainException $exception) {
            return $this->unprocessable($request, $exception);
        }
    }

    private function unprocessable(Request $request, DomainException $exception): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'tariff_change_rejected',
            'message' => $exception->getMessage(),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], 422);
    }
}
