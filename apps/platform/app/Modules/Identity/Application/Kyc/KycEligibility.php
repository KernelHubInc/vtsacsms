<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Kyc;

use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;

final class KycEligibility
{
    public function assertAllowed(?string $subjectId, string $feature): void
    {
        if (! in_array($feature, ['charging', 'payment', 'wallet'], true)) {
            throw new \InvalidArgumentException('Unknown KYC feature.');
        }
        if (! config('kyc.required_for_'.$feature)) {
            return;
        }
        if (! config('kyc.enabled') || $subjectId === null || ! KycVerification::query()
            ->where('subject_id', $subjectId)->where('status', KycStatus::Approved)
            ->where('expires_at', '>', now('UTC'))->exists()) {
            throw new KycException('KYC_REQUIRED', 403);
        }
    }
}
