<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface PaymentProvider extends HostedCheckoutProvider, IncrementalAuthorizationProvider, PaymentAuthorizationProvider, PaymentCaptureProvider, PaymentRefundProvider, PaymentStatusProvider, PaymentVoidProvider, ReconciliationExportProvider, SettlementProvider, TokenizedPaymentMethodProvider, WebhookVerifier
{
    public function key(): string;

    /** @return array<string, bool> */
    public function capabilities(): array;
}
