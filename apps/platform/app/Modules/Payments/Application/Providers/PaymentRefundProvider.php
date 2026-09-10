<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface PaymentRefundProvider
{
    public function refund(PaymentRequest $request): PaymentResult;
}
