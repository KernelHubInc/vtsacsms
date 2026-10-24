<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Application\VerifiedDriverEmailQuery;
use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;
use App\Modules\Notifications\Mail\ChargingSummaryMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            try {
                $email = $this->emails->forPublicId((string) $delivery->subject_id);
                if ($email === null) {
                    throw new \RuntimeException('verified_driver_email_unavailable');
                }
                Mail::to($email)->send(new ChargingSummaryMail($delivery->summary));
                $delivery->forceFill(['sent_at' => now('UTC'), 'claimed_until' => null])->save();
            } catch (Throwable $error) {
                $delay = min(3600, 30 * (2 ** min((int) $delivery->attempt_count, 6)));
                $delivery->forceFill([
                    'available_at' => now('UTC')->addSeconds($delay),
                    'claimed_until' => null,
                ])->save();
                Log::warning('Charging summary email delivery will retry', [
                    'delivery_id' => (string) $delivery->getKey(),
                    'reason' => $email === null ? 'recipient_unavailable' : 'mail_delivery_failed',
                ]);
            }
        }
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
            ])->save();

            return $row;
        });
    }
}
