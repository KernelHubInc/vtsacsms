<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    ): JsonResponse|Response {
        $verified = $verification->verify($user, $hash, (string) $request->attributes->get('correlation_id'));

        if (! $request->expectsJson()) {
            return response()->view('auth.email-verification', ['verified' => $verified], $verified ? 200 : 403)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        if (! $verified) {
            return response()->json(['error' => [
                'code' => 'invalid_verification_link',
                'message' => 'The verification link is invalid.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        return response()->json(['data' => ['email_verified' => true]]);
    }
}
