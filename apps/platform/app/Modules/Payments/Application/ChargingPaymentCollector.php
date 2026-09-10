<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentFact;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\PaymentIntentState;
use InvalidArgumentException;

final readonly class ChargingPaymentCollector
{
    public function __construct(private PaymentIntentService $payments, private PaymentFactsQuery $facts) {}

    public function captureFinalCharge(string $sessionId, int $totalMinor, string $idempotencySuffix): PaymentFact
    {
        $fact = $this->facts->findForBillable('charging_session', $sessionId) ?? throw new InvalidArgumentException('Charging payment intent was not found.');
        $intent = PaymentIntent::query()->findOrFail($fact->id);
        if ($fact->authorizedMinor < $totalMinor) {
            $intent = $this->payments->incrementAuthorization($intent, $totalMinor, 'cdr-increment-'.$idempotencySuffix);
        }
        if (! in_array($intent->state, [PaymentIntentState::Authorized, PaymentIntentState::PartiallyCaptured], true)) {
            throw new InvalidArgumentException('Final charge cannot be captured until authorization is confirmed.');
        }
        $remaining = $totalMinor - (int) $intent->amount_captured_minor;
        if ($remaining > 0) {
            $this->payments->capture($intent, $remaining, 'cdr-capture-'.$idempotencySuffix);
        }

        return $this->facts->get($fact->id);
    }
}
