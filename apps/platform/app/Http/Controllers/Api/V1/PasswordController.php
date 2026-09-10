<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['data' => [
            'message' => 'If the account exists, a password reset link has been sent.',
        ]], 202);
    }

    public function reset(
        ResetPasswordRequest $request,
        CurrentTenant $currentTenant,
        AuditRecorder $audit,
    ): JsonResponse {
        $correlationId = (string) $request->attributes->get('correlation_id');
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password) use ($currentTenant, $audit, $correlationId): void {
                $beforeVersion = $user->security_version;
                $tenantIds = DB::table('memberships')
                    ->where('user_id', $user->getKey())
                    ->pluck('tenant_id')
                    ->filter(static fn (mixed $tenantId): bool => is_string($tenantId))
                    ->values()
                    ->all();
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'security_version' => $user->security_version + 1,
                ])->save();
                $user->mobileTokens()->delete();
                $user->authSessions()->update(['revoked_at' => now('UTC')]);
                event(new PasswordReset($user));

                foreach ($tenantIds as $tenantId) {
                    $currentTenant->run(new TenantContext(
                        tenantId: $tenantId,
                        actorType: ActorType::Human,
                        actorId: $user->public_id,
                        correlationId: $correlationId,
                    ), static function () use ($audit, $user, $beforeVersion): void {
                        $audit->record(new AuditEntry(
                            action: 'identity.password.reset',
                            targetType: 'identity',
                            targetId: $user->public_id,
                            result: AuditResult::Succeeded,
                            before: ['security_version' => $beforeVersion],
                            after: ['security_version' => $user->security_version],
                        ));
                    });
                }
            },
        );

        if ($status !== Password::PasswordReset) {
            return response()->json(['error' => [
                'code' => 'invalid_reset_token',
                'message' => 'The password reset token is invalid or expired.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 422);
        }

        return response()->json(['data' => ['message' => 'Password reset completed.']]);
    }
}
