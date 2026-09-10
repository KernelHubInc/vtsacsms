<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Application\Contracts\PaymentFact;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Domain\Models\PaymentIntent;

final class EloquentPaymentFactsQuery implements PaymentFactsQuery
{
    public function get(string $paymentIntentId): PaymentFact
    {
        return $this->fact(PaymentIntent::query()->findOrFail($paymentIntentId));
    }

    public function findForBillable(string $billableType, string $billableId): ?PaymentFact
    {
        $intent = PaymentIntent::query()->where('billable_type', $billableType)->where('billable_id', $billableId)->latest()->first();

        return $intent === null ? null : $this->fact($intent);
    }

    public function findForProviderReference(string $providerConfigId, string $providerReference): ?PaymentFact
    {
        $intent = PaymentIntent::query()->where('provider_config_id', $providerConfigId)->where('provider_intent_reference', $providerReference)->first();

        return $intent === null ? null : $this->fact($intent);
    }

    private function fact(PaymentIntent $intent): PaymentFact
    {
        return new PaymentFact((string) $intent->getKey(), (string) $intent->currency, $intent->state, (int) $intent->amount_authorized_minor,
            (int) $intent->amount_captured_minor, (int) $intent->amount_refunded_minor, $intent->billing_profile_id, $intent->provider_intent_reference);
    }
}
