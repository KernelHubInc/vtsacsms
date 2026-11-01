<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Reporting\Application\DemoDataAudit;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AuditDemoData extends Command
{
    protected $signature = 'data:audit-demo {tenant : Exact tenant ULID} {--sample-limit=100 : Maximum identifiers per table (1-500)}';

    protected $description = 'Read-only inventory of possible sample data across all admin modules; never deletes records';

    public function handle(DemoDataAudit $audit): int
    {
        try {
            $report = $audit->inspect((string) $this->argument('tenant'), (int) $this->option('sample-limit'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
