<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\BillingProfile;
use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Charging\Application\Contracts\FinalizedChargeDetailRecordQuery;
use App\Modules\Payments\Application\ChargingPaymentCollector;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Domain\PaymentIntentState;
use InvalidArgumentException;

final readonly class FinalizeSessionFinancials
{
    public function __construct(private FinalizedChargeDetailRecordQuery $cdrs, private InvoiceService $invoices,
        private PaymentFactsQuery $payments, private ChargingPaymentCollector $collector, private PaymentAllocationService $allocations) {}

    public function handle(string $chargeDetailRecordId): Invoice
    {
        $cdr = $this->cdrs->get($chargeDetailRecordId);
        $intent = $this->payments->findForBillable('charging_session', $cdr->sessionId) ?? throw new InvalidArgumentException('Charging payment intent was not found.');
        if ($intent->billingProfileId === null) {
            throw new InvalidArgumentException('Charging payment intent has no billing profile.');
        }
        $profile = BillingProfile::query()->findOrFail($intent->billingProfileId);
        $invoice = $this->invoices->issueForChargeDetailRecord($chargeDetailRecordId, $profile);
        $intent = $this->collector->captureFinalCharge($cdr->sessionId, $cdr->totalMinor, $cdr->id);
        if (in_array($intent->state, [PaymentIntentState::Captured, PaymentIntentState::PartiallyCaptured], true)) {
            $this->allocations->allocate($intent, $invoice, min($cdr->totalMinor, $intent->capturedMinor));
        }

        return $invoice->refresh();
    }
}
