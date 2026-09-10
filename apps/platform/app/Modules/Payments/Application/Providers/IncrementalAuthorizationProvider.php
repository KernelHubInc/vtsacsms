<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface IncrementalAuthorizationProvider
{
    public function incrementAuthorization(PaymentRequest $request): PaymentResult;
}
