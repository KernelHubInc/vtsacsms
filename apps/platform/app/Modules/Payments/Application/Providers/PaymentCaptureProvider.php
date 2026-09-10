<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface PaymentCaptureProvider
{
    public function capture(PaymentRequest $request): PaymentResult;
}
