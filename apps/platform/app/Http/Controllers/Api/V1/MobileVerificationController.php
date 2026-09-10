<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StartMobileVerificationRequest;
use App\Http\Requests\Api\VerifyMobileRequest;
use App\Models\User;
use App\Modules\Identity\Application\MobileVerificationService;
use App\Modules\Identity\Domain\Models\MobileVerificationChallenge;
use Illuminate\Http\JsonResponse;

final class MobileVerificationController extends Controller
{
    public function start(
        StartMobileVerificationRequest $request,
        MobileVerificationService $verification,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $challenge = $verification->start($user, (string) $request->validated('mobile_number'));

        return response()->json(['data' => [
            'challenge_id' => $challenge->getKey(),
            'expires_at' => $challenge->expires_at->utc()->toIso8601String(),
        ]], 202);
    }

    public function verify(
        VerifyMobileRequest $request,
        MobileVerificationService $verification,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $challenge = MobileVerificationChallenge::query()
            ->whereKey($request->validated('challenge_id'))
            ->firstOrFail();
        $verification->verify($user, $challenge, (string) $request->validated('code'));

        return response()->json(['data' => ['mobile_verified' => true]]);
    }
}
