<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PaymentFact;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Application\Providers\PaymentProvider;
use App\Modules\Payments\Application\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Application\Providers\PaymentResult;
use App\Modules\Payments\Application\Providers\ReconciliationRecord;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use Carbon\CarbonImmutable;

final readonly class ProviderFinancialGateway
{
    public function __construct(private PaymentProviderRegistry $providers, private PaymentFactsQuery $facts) {}

    /** @return list<ReconciliationRecord> */
    public function reconciliationExport(string $configurationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->provider($configurationId)->reconciliationExport($from, $to);
    }

    public function paymentByProviderReference(string $configurationId, string $providerReference): ?PaymentFact
    {
        return $this->facts->findForProviderReference($configurationId, $providerReference);
    }

    public function submitSettlement(string $configurationId, string $batchId, int $amountMinor, string $currency, string $idempotencyKey): PaymentResult
    {
        return $this->provider($configurationId)->submitSettlement($batchId, $amountMinor, $currency, $idempotencyKey);
    }

    private function provider(string $configurationId): PaymentProvider
    {
        return $this->providers->for(PaymentProviderConfig::query()->findOrFail($configurationId));
    }
}
