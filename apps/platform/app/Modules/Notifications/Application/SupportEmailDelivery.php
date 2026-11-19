<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Models\SupportEmailDelivery as Delivery;
use App\Modules\Notifications\Mail\SupportTicketMail;
use App\Modules\Support\Application\SupportEmailQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final readonly class SupportEmailDelivery
{
    public function deliverDue(int $limit = 50): void
    {
        for ($i = 0; $i < $limit; $i++) {
            $delivery = DB::transaction(function (): ?Delivery {
                $row = Delivery::query()->whereNull('sent_at')->whereNull('failed_at')->where('available_at', '<=', now('UTC'))
                    ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now('UTC')))
                    ->orderBy('available_at')->lockForUpdate()->first();
                $row?->forceFill(['claimed_until' => now('UTC')->addMinutes(5), 'attempt_count' => (int) $row->attempt_count + 1])->save();

                return $row;
            });
            if ($delivery === null) {
                return;
            }
            $reason = 'mail_transport_unavailable';
            try {
                if (! app(SupportMailTransport::class)->ready()) {
                    throw new \RuntimeException($reason);
                }
                $reason = 'support_recipient_unavailable';
                $details = app(SupportEmailQuery::class)->message((string) $delivery->ticket_id, $delivery->support_message_id);
                if ($details === null) {
                    throw new \RuntimeException($reason);
                }
                $reason = 'mail_delivery_failed';
                $sent = Mail::to($details['email'])->send(new SupportTicketMail($details, (string) $delivery->getKey()));
                if ($sent === null) {
                    throw new \RuntimeException($reason);
                }
                $delivery->forceFill(['sent_at' => now('UTC'), 'claimed_until' => null, 'message_id' => $sent->getMessageId(), 'last_error_code' => null])->save();
            } catch (Throwable) {
                $delivery->forceFill(['claimed_until' => null, 'available_at' => now('UTC')->addSeconds(min(3600, 30 * (2 ** min((int) $delivery->attempt_count, 6)))),
                    'failed_at' => $delivery->attempt_count >= 8 ? now('UTC') : null, 'last_error_code' => $reason])->save();
                Log::warning('Support email delivery deferred', ['delivery_id' => $delivery->getKey(), 'reason' => $reason]);
            }
        }
    }
}
