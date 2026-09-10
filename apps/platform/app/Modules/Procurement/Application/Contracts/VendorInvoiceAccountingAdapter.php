<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\Contracts;

use App\Modules\Procurement\Domain\Models\VendorInvoice;

interface VendorInvoiceAccountingAdapter
{
    /** @return array{external_reference: string, exported_at: string} */
    public function export(VendorInvoice $invoice, string $idempotencyKey): array;
}
