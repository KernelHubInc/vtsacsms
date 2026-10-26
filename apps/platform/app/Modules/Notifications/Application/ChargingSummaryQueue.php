<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;

final readonly class ChargingSummaryQueue
{
    /** @param array<string, int|string|null> $summary */
    public function record(string $sessionId, string $subjectId, array $summary): void
    {
        ChargingEmailDelivery::query()->firstOrCreate(
            ['session_id' => $sessionId],
            [
                'subject_id' => $subjectId,
                'summary' => ['session_id' => $sessionId, ...$summary],
                'available_at' => now('UTC'),
            ],
        );
    }
}
