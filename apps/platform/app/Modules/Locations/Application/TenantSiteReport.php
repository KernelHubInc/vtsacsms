<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;

final readonly class TenantSiteReport
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AuditRecorder $audit,
    ) {}

    /** @return list<array{id: string, code: string, name: string, active: bool}> */
    public function generate(User $user): array
    {
        $rows = array_values($this->sites->for($user)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(static fn ($site): array => [
                'id' => (string) $site->getKey(),
                'code' => (string) $site->code,
                'name' => (string) $site->name,
                'active' => (bool) $site->is_active,
            ])->all());

        $this->audit->record(new AuditEntry(
            action: 'reporting.sites.generated',
            targetType: 'site_access_report',
            targetId: null,
            result: AuditResult::Succeeded,
            after: ['row_count' => count($rows)],
        ));

        return $rows;
    }
}
