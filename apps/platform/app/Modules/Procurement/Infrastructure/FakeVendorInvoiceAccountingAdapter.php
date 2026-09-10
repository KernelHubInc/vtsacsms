<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure;

use App\Modules\Procurement\Application\Contracts\VendorInvoiceAccountingAdapter;
use App\Modules\Procurement\Domain\Models\VendorInvoice;

final class FakeVendorInvoiceAccountingAdapter implements VendorInvoiceAccountingAdapter
{
    public function export(VendorInvoice $invoice, string $idempotencyKey): array
    {
        return [
            'external_reference' => 'local-'.hash('sha256', $idempotencyKey),
            'exported_at' => now('UTC')->toISOString(),
        ];
    }
}
