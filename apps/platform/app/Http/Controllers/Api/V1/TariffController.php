<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTariffRequest;
use App\Http\Resources\Api\V1\TariffResource;
use App\Models\User;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tariffs\Domain\Models\Tariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TariffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tariff::class);

        return TariffResource::collection(
            Tariff::query()->with(['versions.components', 'versions.discounts'])->latest()->cursorPaginate(50),
        );
    }

    public function show(Request $request, string $tariff): TariffResource
    {
        $model = Tariff::query()->with(['versions.components', 'versions.discounts'])->whereKey($tariff)->firstOrFail();
        Gate::authorize('view', $model);

        return new TariffResource($model);
    }

    public function store(StoreTariffRequest $request, TariffManagementService $tariffs): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::authorize('create', Tariff::class);

        return (new TariffResource($tariffs->create([
            'name' => (string) $request->validated('name'),
            'description' => $request->validated('description'),
            'currency' => (string) $request->validated('currency'),
        ])))->response()->setStatusCode(201);
    }
}
