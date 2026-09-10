<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface SettlementProvider
{
    public function submitSettlement(string $batchId, int $amountMinor, string $currency, string $idempotencyKey): PaymentResult;
}
