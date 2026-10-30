<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\ConsumerEnrollment;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class RegistrationController extends Controller
{
    public function legacy(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'app_update_required', 'message' => 'Update the app to create an account.',
            'correlation_id' => $request->attributes->get('correlation_id'), 'errors' => [],
        ]], 426)->header('Cache-Control', 'no-store');
    }

    public function __invoke(RegisterRequest $request, ConsumerEnrollment $enrollment): JsonResponse
    {
        $tenantId = (string) $request->validated('tenant_id');
        $enrollment->scope($tenantId);

        $user = DB::transaction(function () use ($request, $tenantId, $enrollment): User {
            $user = User::query()->create([
                'name' => $request->validated('name'),
                'first_name' => $request->validated('first_name'),
                'middle_name' => $request->validated('middle_name'),
                'last_name' => $request->validated('last_name'),
                'birth_date' => $request->validated('birth_date'),
                'email' => mb_strtolower((string) $request->validated('email')),
                'password' => $request->validated('password'),
                'activated_at' => now('UTC'),
            ]);
            $enrollment->enroll((int) $user->getKey(), $tenantId);

            app(CurrentTenant::class)->run(new TenantContext(
                $tenantId, ActorType::Human, (string) $user->public_id, (string) Str::ulid(),
            ), function () use ($user, $request): void {
                app(AuditRecorder::class)->record(new AuditEntry(
                    'identity.user.registered', 'identity', $user->public_id, AuditResult::Succeeded,
                ));
                app(DriverVehicles::class)->save((string) $user->public_id, (string) Str::ulid(), [
                    'nickname' => 'My vehicle', 'plate_number' => $request->validated('plate_number'),
                    'plate_pending' => $request->boolean('plate_pending'), 'is_default' => true,
                ]);
            });

            return $user;
        });

        $verificationEmailSent = true;
        try {
            $user->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            // Registration is committed; a mail outage must not invite duplicate signup.
            $verificationEmailSent = false;
            Log::warning('identity.registration.verification_email_failed', [
                'tenant_id' => $tenantId,
                'correlation_id' => $request->attributes->get('correlation_id'),
                'exception_class' => $exception::class,
            ]);
        }

        return response()->json(['data' => [
            'message' => $verificationEmailSent
                ? 'Registration complete. Check your email to verify your account.'
                : 'Your account was created, but the verification email could not be sent. Sign in to request another email.',
            'verification_email_sent' => $verificationEmailSent,
        ]], 201)->header('Cache-Control', 'no-store');
    }
}
