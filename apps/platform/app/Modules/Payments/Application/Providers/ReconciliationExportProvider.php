<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

use Carbon\CarbonImmutable;

interface ReconciliationExportProvider
{
    /** @return list<ReconciliationRecord> */
    public function reconciliationExport(CarbonImmutable $from, CarbonImmutable $to): array;
}
