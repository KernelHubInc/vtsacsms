<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Application\VerifiedDriverEmailQuery;
use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;
use App\Modules\Notifications\Mail\ChargingSummaryMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final readonly class ChargingSummaryDelivery
{
    public function __construct(private VerifiedDriverEmailQuery $emails) {}

    public function deliverDue(int $limit = 100): void
    {
        for ($sent = 0; $sent < $limit; $sent++) {
            $delivery = $this->claim();
            if ($delivery === null) {
                return;
            }

            $email = null;
            $reason = 'receipt_generation_failed';
            try {
                $reason = 'mailer_not_delivering';
                $mailer = (string) config('mail.default');
                if (! $this->deliversExternally($mailer)) {
                    throw new \RuntimeException('mailer_not_delivering');
                }
                $reason = 'recipient_unavailable';
                $email = $this->emails->forPublicId((string) $delivery->subject_id);
                if ($email === null) {
                    throw new \RuntimeException('verified_driver_email_unavailable');
                }
                $reason = 'receipt_generation_failed';
                $message = Mail::to($email)->send(new ChargingSummaryMail($delivery->summary));
                if ($message === null) {
                    $reason = 'send_cancelled';
                    throw new \RuntimeException('send_cancelled');
                }
                $delivery->forceFill([
                    'sent_at' => now('UTC'), 'claimed_until' => null,
                    'last_error_code' => null, 'message_id' => $message->getMessageId(),
                ])->save();
            } catch (Throwable $error) {
                $reason = $error instanceof TransportExceptionInterface ? 'mail_transport_failed' : $reason;
                $delay = min(3600, 30 * (2 ** min((int) $delivery->attempt_count, 6)));
                $delivery->forceFill([
                    'available_at' => now('UTC')->addSeconds($delay),
                    'claimed_until' => null,
                    'last_error_code' => $reason,
                ])->save();
                Log::warning('Charging summary email delivery will retry', [
                    'delivery_id' => (string) $delivery->getKey(),
                    'reason' => $reason,
                ]);
            }
        }
    }

    /**
     * A fallback that writes to a log is not evidence of external delivery.
     *
     * @param  list<string>  $visited
     */
    private function deliversExternally(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $transport = config("mail.mailers.{$mailer}.transport");
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = config("mail.mailers.{$mailer}.mailers", []);
            if ($children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (! $this->deliversExternally($child, [...$visited, $mailer])) {
                    return false;
                }
            }

            return true;
        }

        return is_string($transport) && ! in_array($transport, ['log', 'array'], true);
    }

    private function claim(): ?ChargingEmailDelivery
    {
        return DB::transaction(function (): ?ChargingEmailDelivery {
            $row = ChargingEmailDelivery::query()
                ->whereNull('sent_at')
                ->where('available_at', '<=', now('UTC'))
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now('UTC')))
                ->orderBy('available_at')
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return null;
            }
            $row->forceFill([
                'claimed_until' => now('UTC')->addMinutes(5),
                'attempt_count' => (int) $row->attempt_count + 1,
                'last_attempt_at' => now('UTC'),
                'mailer' => config('mail.default'),
            ])->save();

            return $row;
        });
    }
}
