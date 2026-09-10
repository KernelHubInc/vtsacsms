<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface PaymentAuthorizationProvider
{
    public function authorize(PaymentRequest $request): PaymentResult;
}
