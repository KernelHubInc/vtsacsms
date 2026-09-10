<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

interface PaymentFactsQuery
{
    public function get(string $paymentIntentId): PaymentFact;

    public function findForBillable(string $billableType, string $billableId): ?PaymentFact;

    public function findForProviderReference(string $providerConfigId, string $providerReference): ?PaymentFact;
}
