<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
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
        AuditRecorder $audit,
    ): JsonResponse {
        $subject = $request->user();

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
    }
}
