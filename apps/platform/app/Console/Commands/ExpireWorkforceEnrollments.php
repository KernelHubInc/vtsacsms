<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\Models\WorkforceEnrollment;
use Illuminate\Console\Command;

final class ExpireWorkforceEnrollments extends Command
{
    protected $signature = 'security:expire-workforce-enrollments';

    protected $description = 'Clear expired workforce setup verifiers while retaining enrollment and audit references';

    public function handle(): int
    {
        $count = WorkforceEnrollment::withoutGlobalScopes()->where('expires_at', '<=', now('UTC'))->whereNotNull('token_hash')->update(['token_hash' => null]);
        $this->info("Expired workforce setup verifiers cleared: {$count}");

        return self::SUCCESS;
    }
}
