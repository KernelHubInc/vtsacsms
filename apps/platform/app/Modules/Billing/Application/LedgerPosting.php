<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\LedgerAccount;

final readonly class LedgerPosting
{
    public function __construct(public LedgerAccount $account, public int $debitMinor = 0, public int $creditMinor = 0) {}
}
