<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\Features\Feature;
use App\Foundation\Features\FeatureFlags;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireEnabledFeature
{
    public function __construct(private FeatureFlags $flags) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $selected = Feature::from($feature);

        if ($this->flags->enabled($selected)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'error' => [
                    'code' => 'milestone_2_feature_disabled',
                    'message' => FeatureFlags::MILESTONE_TWO_MESSAGE,
                    'feature' => $selected->value,
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ],
            ], Response::HTTP_CONFLICT);
        }

        return response()->view('milestone-two', ['feature' => $selected], Response::HTTP_CONFLICT);
    }
}
