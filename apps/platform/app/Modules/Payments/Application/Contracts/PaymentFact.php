<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Modules\Payments\Domain\PaymentIntentState;

final readonly class PaymentFact
{
    public function __construct(public string $id, public string $currency, public PaymentIntentState $state,
        public int $authorizedMinor, public int $capturedMinor, public int $refundedMinor,
        public ?string $billingProfileId, public ?string $providerReference) {}
}
