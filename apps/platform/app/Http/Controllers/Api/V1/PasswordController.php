<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Models\User;
use App\Modules\Identity\Application\ResetAccountPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;

final class PasswordController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $email = $request->query('email', '');

        return $this->form($token, is_string($email) && strlen($email) <= 254 ? $email : '');
    }

    private function form(string $token = '', string $email = '', ?string $error = null, bool $completed = false): Response
    {
        return response()->view('auth.reset-password', compact('token', 'email', 'error', 'completed'), $error === null ? 200 : 422)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['data' => [
            'message' => 'If the account exists, a password reset link has been sent.',
        ]], 202);
    }

    public function reset(
        ResetPasswordRequest $request,
        ResetAccountPassword $passwords,
    ): JsonResponse|Response {
        $correlationId = (string) $request->attributes->get('correlation_id');
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            fn (User $user, string $password) => $passwords->reset($user, $password, $correlationId),
        );

        if ($status !== Password::PasswordReset) {
            if ($request->routeIs('password.update')) {
                return $this->form((string) $request->validated('token'), (string) $request->validated('email'), 'This reset link is invalid or expired. Request a new link from the app.');
            }

            return response()->json(['error' => [
                'code' => 'invalid_reset_token',
                'message' => 'The password reset token is invalid or expired.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 422);
        }

        return $request->routeIs('password.update')
            ? $this->form(completed: true)
            : response()->json(['data' => ['message' => 'Password reset completed.']]);
    }
}
