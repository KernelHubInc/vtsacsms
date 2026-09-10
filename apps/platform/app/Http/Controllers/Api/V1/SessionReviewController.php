<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResolveSessionReviewRequest;
use App\Http\Resources\Api\V1\ChargingSessionResource;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Application\ManualSessionReviewService;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;
use Illuminate\Http\JsonResponse;

final class SessionReviewController extends Controller
{
    public function resolve(
        ResolveSessionReviewRequest $request,
        string $session,
        AccessibleChargingSessionsQuery $sessions,
        ManualSessionReviewService $reviews,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $model = $sessions->for($user, PermissionKey::ChargingSessionReview)->whereKey($session)->firstOrFail();
        try {
            $review = $reviews->resolve(
                $model,
                (string) $request->validated('decision'),
                (string) $request->validated('notes'),
                $request->validated('adjustments', []),
            );
        } catch (DomainException $exception) {
            return response()->json(['error' => [
                'code' => 'charging_review_rejected',
                'message' => $exception->getMessage(),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 422);
        }

        return response()->json(['data' => [
            'review_id' => (string) $review->getKey(),
            'session' => (new ChargingSessionResource($model->refresh()->load(['commands', 'chargeDetailRecords'])))->resolve($request),
        ]]);
    }
}
