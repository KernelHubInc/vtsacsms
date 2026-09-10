<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Application\EInvoicing\BirElectronicInvoicingAdapter;
use App\Modules\Billing\Domain\Models\Invoice;

final class UnconfiguredBirElectronicInvoicingAdapter implements BirElectronicInvoicingAdapter
{
    public function submit(Invoice $invoice): array
    {
        return ['status' => 'not_configured', 'external_reference' => null, 'payload_hash' => null,
            'safe_message' => 'BIR electronic invoicing requires legal, registration, and endpoint configuration.'];
    }
}
