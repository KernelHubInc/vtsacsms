<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface PaymentStatusProvider
{
    public function retrievePaymentStatus(PaymentRequest $request): PaymentResult;
}
