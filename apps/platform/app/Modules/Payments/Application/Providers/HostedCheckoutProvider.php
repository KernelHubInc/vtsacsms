<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface HostedCheckoutProvider
{
    public function createHostedCheckout(PaymentRequest $request, string $returnUrl): PaymentResult;
}
