<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        AuditRecorder $audit,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $subject = User::query()->where('public_id', $user)->first();

        if (
            ! $subject instanceof User
            || $subject->public_id === null
            || ! hash_equals($subject->public_id, $user)
            || ! hash_equals(sha1($subject->getEmailForVerification()), $hash)
        ) {
            return response()->json(['error' => [
                'code' => 'invalid_verification_link',
                'message' => 'The verification link is invalid.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        $tenantId = DB::table('memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.user_id', $subject->getKey())
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('tenants.status', TenantStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('memberships.expires_at')
                    ->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->orderBy('memberships.tenant_id')
            ->value('memberships.tenant_id');

        if (! is_string($tenantId)) {
            return response()->json(['error' => [
                'code' => 'invalid_verification_link',
                'message' => 'The verification link is invalid.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]], 403);
        }

        $context = new TenantContext(
            tenantId: $tenantId,
            actorType: ActorType::Human,
            actorId: $subject->public_id,
            correlationId: (string) $request->attributes->get('correlation_id'),
        );

        return $currentTenant->run($context, function () use ($subject, $audit): JsonResponse {
            if ($subject->markEmailAsVerified()) {
                event(new Verified($subject));
                $audit->record(new AuditEntry(
                    action: 'identity.email.verified',
                    targetType: 'identity',
                    targetId: $subject->public_id,
                    result: AuditResult::Succeeded,
                    before: ['email_verified_at' => null],
                    after: ['email_verified_at' => now('UTC')->toIso8601String()],
                ));
            }

            return response()->json(['data' => ['email_verified' => true]]);
        });
    }
}
