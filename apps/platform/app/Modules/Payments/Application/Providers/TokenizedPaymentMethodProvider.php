<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface TokenizedPaymentMethodProvider
{
    public function attachTokenizedPaymentMethod(string $providerCustomerReference, string $token): PaymentResult;
}
