<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use Illuminate\Http\JsonResponse;

final class AppConfigurationController extends Controller
{
    public function __invoke(MapConfigurationResolver $maps): JsonResponse
    {
        return response()->json([
            'data' => [
                'map' => $maps->forSurface(MapSurface::Mobile)->toPublicArray(),
            ],
        ])->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }
}
