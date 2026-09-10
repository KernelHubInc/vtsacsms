<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PublicStationSearchRequest;
use App\Modules\Locations\Application\PublicStationSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class PublicStationSearchController extends Controller
{
    public function __invoke(PublicStationSearchRequest $request, PublicStationSearch $search): JsonResponse
    {
        $filters = $request->filters();
        ksort($filters);
        $cacheKey = 'public-stations:'.hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        $data = Cache::remember($cacheKey, 30, fn (): array => $search->search($filters));
        $etag = '"'.hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json(['data' => $data, 'meta' => ['generated_at' => now('UTC')->toIso8601String()]])
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=120')
            ->header('ETag', $etag);
    }
}
