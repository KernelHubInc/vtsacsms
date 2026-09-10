<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\EInvoicing;

use App\Modules\Billing\Domain\Models\Invoice;

interface BirElectronicInvoicingAdapter
{
    /** @return array{status:string, external_reference:?string, payload_hash:?string, safe_message:?string} */
    public function submit(Invoice $invoice): array;
}
