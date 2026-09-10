<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Payments\Infrastructure\FakePaymentProvider;
use App\Modules\Payments\Infrastructure\StripeSandboxPaymentProvider;

final readonly class PaymentProviderRegistry
{
    public function __construct(private FakePaymentProvider $fake, private StripeSandboxPaymentProvider $stripe) {}

    public function for(PaymentProviderConfig $configuration): PaymentProvider
    {
        return match ($configuration->provider) {
            'fake' => $this->fake,
            'stripe_sandbox' => $this->stripe,
            default => throw new ProviderException('Unsupported payment provider configuration.'),
        };
    }
}
