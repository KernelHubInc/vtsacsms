<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\EmailVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['data' => [
            'message' => 'If verification is required, a new link has been sent.',
        ]], 202);
    }

    public function verify(
        Request $request,
        string $user,
        string $hash,
        EmailVerificationService $verification,
    ): JsonResponse|View {
        if (! $verification->verify($user, $hash, (string) $request->attributes->get('correlation_id'))) {
            return response()->json(['error' => [
                'code' => 'invalid_verification_link',
                'message' => 'The verification link is invalid.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        if (! $request->expectsJson()) {
            return view('auth.email-verified');
        }

        return response()->json(['data' => ['email_verified' => true]]);
    }
}
