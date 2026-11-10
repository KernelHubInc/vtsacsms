<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Modules\Identity\Application\RegisterDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegistrationController extends Controller
{
    public function legacy(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'app_update_required', 'message' => 'Update the app to create an account.',
            'correlation_id' => $request->attributes->get('correlation_id'), 'errors' => [],
        ]], 426)->header('Cache-Control', 'no-store');
    }

    public function __invoke(RegisterRequest $request, RegisterDriver $registration): JsonResponse
    {
        $user = $registration->create($request->validated());
        $verificationEmailSent = $registration->sendVerification($user);

        return response()->json(['data' => [
            'message' => $verificationEmailSent
                ? 'Registration complete. Check your email to verify your account.'
                : 'Your account was created, but the verification email could not be sent. Sign in to request another email.',
            'verification_email_sent' => $verificationEmailSent,
        ]], 201)->header('Cache-Control', 'no-store');
    }
}
