<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LifecycleReasonRequest;
use App\Models\User;
use App\Modules\Organizations\Application\MembershipLifecycleService;
use App\Modules\Organizations\Domain\Models\Membership;
use Illuminate\Http\JsonResponse;

final class MembershipLifecycleController extends Controller
{
    public function suspend(
        LifecycleReasonRequest $request,
        string $membership,
        MembershipLifecycleService $lifecycle,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $subject = Membership::query()->whereKey($membership)->firstOrFail();
        $lifecycle->suspend($actor, $subject, (string) $request->validated('reason'));

        return response()->json(['data' => ['status' => $subject->status->value]]);
    }

    public function activate(
        LifecycleReasonRequest $request,
        string $membership,
        MembershipLifecycleService $lifecycle,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $subject = Membership::query()->whereKey($membership)->firstOrFail();
        $lifecycle->activate($actor, $subject, (string) $request->validated('reason'));

        return response()->json(['data' => ['status' => $subject->status->value]]);
    }
}
