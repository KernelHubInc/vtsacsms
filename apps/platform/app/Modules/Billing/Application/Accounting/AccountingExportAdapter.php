<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Accounting;

use App\Modules\Billing\Domain\Models\LedgerTransaction;

interface AccountingExportAdapter
{
    public function key(): string;

    /**
     * @param  iterable<LedgerTransaction>  $transactions
     * @return array<string, scalar|null>
     */
    public function export(iterable $transactions): array;
}
