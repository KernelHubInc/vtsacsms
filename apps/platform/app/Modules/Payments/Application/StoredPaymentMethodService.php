<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Payments\Domain\Models\StoredPaymentMethod;
use InvalidArgumentException;

final class StoredPaymentMethodService
{
    public function store(PaymentProviderConfig $configuration, string $userId, string $providerToken, string $type = 'card', ?string $brand = null, ?string $last4 = null, ?int $expiryMonth = null, ?int $expiryYear = null): StoredPaymentMethod
    {
        $digits = preg_replace('/[\s-]+/', '', $providerToken);
        if ($providerToken === '' || (is_string($digits) && preg_match('/^[0-9]{12,19}$/', $digits) === 1)
            || ($last4 !== null && preg_match('/^[0-9]{4}$/', $last4) !== 1)) {
            throw new InvalidArgumentException('Invalid token reference or safe display metadata.');
        }
        $hash = hash('sha256', $configuration->getKey().'|'.$providerToken);

        return StoredPaymentMethod::query()->firstOrCreate(
            ['provider_config_id' => $configuration->getKey(), 'provider_token_hash' => $hash],
            ['user_id' => $userId, 'provider_token_encrypted' => $providerToken, 'type' => $type,
                'display_brand' => $brand, 'display_last4' => $last4, 'expiry_month' => $expiryMonth, 'expiry_year' => $expiryYear],
        );
    }
}
