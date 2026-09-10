<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Application\Accounting\AccountingExportAdapter;
use App\Modules\Billing\Domain\Models\LedgerTransaction;

final class FakeAccountingExportAdapter implements AccountingExportAdapter
{
    public function key(): string
    {
        return 'fake';
    }

    /**
     * @param  iterable<LedgerTransaction>  $transactions
     * @return array<string, scalar|null>
     */
    public function export(iterable $transactions): array
    {
        $count = 0;
        $ids = [];
        foreach ($transactions as $transaction) {
            $count++;
            $ids[] = (string) $transaction->getKey();
        }

        return ['transaction_count' => $count, 'content_hash' => hash('sha256', implode('|', $ids))];
    }
}
