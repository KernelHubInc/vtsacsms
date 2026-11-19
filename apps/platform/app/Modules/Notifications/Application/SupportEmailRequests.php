<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Models\SupportEmailDelivery;

final readonly class SupportEmailRequests
{
    public function enqueue(string $ticketId, ?string $messageId = null): void
    {
        SupportEmailDelivery::query()->firstOrCreate(['idempotency_key' => $messageId === null ? 'ack:'.$ticketId : 'reply:'.$messageId],
            ['ticket_id' => $ticketId, 'support_message_id' => $messageId, 'kind' => $messageId === null ? 'acknowledgement' : 'reply', 'available_at' => now('UTC')]);
    }
}
