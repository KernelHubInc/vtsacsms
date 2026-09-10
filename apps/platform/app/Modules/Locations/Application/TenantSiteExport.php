<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use RuntimeException;

final readonly class TenantSiteExport
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AuditRecorder $audit,
    ) {}

    public function csv(User $user): string
    {
        $rows = $this->sites->for($user)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_active']);
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Unable to create the site export stream.');
        }

        fputcsv($stream, ['site_id', 'code', 'name', 'status']);

        foreach ($rows as $site) {
            fputcsv($stream, [
                $site->getKey(),
                $this->safeCell((string) $site->code),
                $this->safeCell((string) $site->name),
                $site->is_active ? 'active' : 'inactive',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new RuntimeException('Unable to read the site export stream.');
        }

        $this->audit->record(new AuditEntry(
            action: 'reporting.sites.exported',
            targetType: 'site_access_export',
            targetId: null,
            result: AuditResult::Succeeded,
            after: ['row_count' => $rows->count(), 'format' => 'csv'],
        ));

        return $csv;
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
