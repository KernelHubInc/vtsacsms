<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\Contracts\MobileVerificationSender;
use App\Modules\Identity\Domain\Models\MobileVerificationChallenge;
use Illuminate\Validation\ValidationException;

final readonly class MobileVerificationService
{
    public function __construct(
        private MobileVerificationSender $sender,
        private AuditRecorder $audit,
    ) {}

    public function start(User $user, string $mobileNumber): MobileVerificationChallenge
    {
        $normalized = preg_replace('/\s+/', '', $mobileNumber) ?? $mobileNumber;
        $mobileHash = hash('sha256', $normalized);
        $code = app()->environment(['local', 'testing']) ? '000000' : (string) random_int(100000, 999999);

        $user->forceFill([
            'mobile_number' => $normalized,
            'mobile_number_hash' => $mobileHash,
            'mobile_verified_at' => null,
        ])->save();

        $challenge = MobileVerificationChallenge::query()->create([
            'user_id' => $user->getKey(),
            'mobile_number_hash' => $mobileHash,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now('UTC')->addMinutes(10),
        ]);
        $this->sender->send($normalized, $code);

        $this->audit->record(new AuditEntry(
            action: 'identity.mobile_verification.requested',
            targetType: 'mobile_verification_challenge',
            targetId: (string) $challenge->getKey(),
            result: AuditResult::Succeeded,
            after: ['expires_at' => $challenge->expires_at->utc()->toIso8601String()],
        ));

        return $challenge;
    }

    public function verify(User $user, MobileVerificationChallenge $challenge, string $code): void
    {
        if (
            $challenge->user_id !== $user->getKey()
            || $challenge->consumed_at !== null
            || $challenge->expires_at->isPast()
            || $challenge->attempts >= 5
        ) {
            throw ValidationException::withMessages(['code' => 'The verification challenge is invalid.']);
        }

        $challenge->increment('attempts');

        if (! hash_equals($challenge->code_hash, hash('sha256', $code))) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid.']);
        }

        $challenge->forceFill(['consumed_at' => now('UTC')])->save();
        $user->forceFill(['mobile_verified_at' => now('UTC')])->save();

        $this->audit->record(new AuditEntry(
            action: 'identity.mobile_number.verified',
            targetType: 'identity',
            targetId: $user->public_id,
            result: AuditResult::Succeeded,
            before: ['mobile_verified_at' => null],
            after: ['mobile_verified_at' => $user->mobile_verified_at?->toIso8601String()],
        ));
    }
}
