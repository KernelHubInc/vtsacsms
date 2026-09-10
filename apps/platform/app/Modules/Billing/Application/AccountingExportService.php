<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Application\Accounting\AccountingExportAdapter;
use App\Modules\Billing\Domain\Models\AccountingExport;
use App\Modules\Billing\Domain\Models\LedgerTransaction;
use Carbon\CarbonImmutable;

final readonly class AccountingExportService
{
    public function __construct(private AccountingExportAdapter $adapter) {}

    public function export(CarbonImmutable $from, CarbonImmutable $to): AccountingExport
    {
        $transactions = LedgerTransaction::query()->whereBetween('posted_at', [$from, $to])->orderBy('posted_at')->get();
        $result = $this->adapter->export($transactions);
        $hash = is_string($result['content_hash'] ?? null) ? $result['content_hash'] : hash('sha256', '');

        return AccountingExport::query()->create(['adapter' => $this->adapter->key(), 'status' => 'exported',
            'period_start' => $from, 'period_end' => $to, 'transaction_count' => $transactions->count(),
            'content_hash' => $hash, 'safe_metadata' => $result, 'exported_at' => now('UTC')]);
    }
}
