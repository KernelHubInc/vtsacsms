<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Application\VerifiedDriverEmailQuery;
use App\Modules\Notifications\Domain\Models\SupportEscalationDelivery as Delivery;
use App\Modules\Notifications\Mail\SupportEscalationMail;
use App\Modules\Organizations\Application\SupportSuperAdministrators;
use App\Modules\Support\Application\SupportEmailQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

final readonly class SupportEscalationEmails
{
    public function enqueue(string $ticketId, string $escalationId): void
    {
        Validator::make(compact('ticketId', 'escalationId'), ['ticketId' => ['required', 'ulid'], 'escalationId' => ['required', 'ulid']])->validate();
        $recipients = app(SupportSuperAdministrators::class)->ids();
        foreach ($recipients === [] ? [null] : $recipients as $recipient) {
            $row = Delivery::query()->firstOrCreate(['idempotency_key' => $escalationId.':'.($recipient ?? 'unassigned')], [
                'ticket_id' => $ticketId, 'escalation_id' => $escalationId, 'recipient_public_id' => $recipient,
                'available_at' => now('UTC'), 'failed_at' => $recipient === null ? now('UTC') : null,
                'last_error_code' => $recipient === null ? 'no_eligible_superadmin' : null,
            ]);
            abort_unless($row->ticket_id === $ticketId && $row->escalation_id === $escalationId, 409);
            if ($recipient === null && $row->wasRecentlyCreated) {
                Log::warning('Support escalation has no eligible superadmin', ['delivery_id' => $row->getKey()]);
            }
        }
    }

    public function deliverDue(int $limit = 50, ?string $deliveryId = null): void
    {
        for ($i = 0; $i < $limit; $i++) {
            $delivery = DB::transaction(function () use ($deliveryId): ?Delivery {
                $row = Delivery::query()->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->where('available_at', '<=', now('UTC'))
                    ->when($deliveryId !== null, fn ($query) => $query->whereKey($deliveryId))
                    ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now('UTC')))
                    ->orderBy('available_at')->lockForUpdate()->first();
                $row?->forceFill(['claimed_until' => now('UTC')->addMinutes(5), 'attempt_count' => (int) $row->attempt_count + 1])->save();

                return $row;
            });
            if ($delivery === null) {
                return;
            }
            $reason = 'recipient_lookup_failed';
            try {
                $recipient = $delivery->recipient_public_id;
                $details = app(SupportEmailQuery::class)->escalation((string) $delivery->ticket_id);
                $email = is_string($recipient) && app(SupportSuperAdministrators::class)->includes($recipient)
                    ? app(VerifiedDriverEmailQuery::class)->forPublicId($recipient) : null;
                if ($email === null || $details === null) {
                    $delivery->forceFill(['cancelled_at' => now('UTC'), 'claimed_until' => null, 'last_error_code' => 'recipient_or_ticket_unavailable'])->save();

                    continue;
                }
                $reason = 'mail_transport_unavailable';
                if (! app(SupportMailTransport::class)->ready()) {
                    throw new \RuntimeException($reason);
                }
                $reason = 'mail_delivery_failed';
                $sent = Mail::to($email)->send(new SupportEscalationMail($details, (string) $delivery->getKey()));
                if ($sent === null) {
                    throw new \RuntimeException($reason);
                }
                $delivery->forceFill(['sent_at' => now('UTC'), 'claimed_until' => null, 'message_id' => $sent->getMessageId(), 'last_error_code' => null])->save();
            } catch (Throwable) {
                $delivery->forceFill(['claimed_until' => null, 'available_at' => now('UTC')->addSeconds(min(3600, 30 * (2 ** min((int) $delivery->attempt_count, 6)))),
                    'failed_at' => $delivery->attempt_count >= 8 ? now('UTC') : null, 'last_error_code' => $reason])->save();
                Log::warning('Support escalation email deferred', ['delivery_id' => $delivery->getKey(), 'reason' => $reason]);
            }
        }
    }
}
