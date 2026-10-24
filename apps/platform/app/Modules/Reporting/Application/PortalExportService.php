<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Reporting\Domain\Models\PortalExport;
use App\Modules\Reporting\Jobs\GeneratePortalExport;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use DomainException;
use Illuminate\Support\Str;

final readonly class PortalExportService
{
    public function __construct(
        private CurrentTenant $tenant,
        private PortalReportAccess $access,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, scalar|null> $filters */
    public function queue(User $user, string $type, array $filters = [], string $format = 'csv'): PortalExport
    {
        $this->access->assertCanExport($user, $type);
        if (! in_array($format, ['csv', 'pdf'], true)) {
            throw new DomainException('Unsupported report format.');
        }

        $filters = array_intersect_key($filters, array_flip(['from', 'to']));
        foreach ($filters as $value) {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
                || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw new DomainException('Report dates must use YYYY-MM-DD.');
            }
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            throw new DomainException('The end date must be after the start date.');
        }
        $filters['_format'] = $format;

        $context = $this->tenant->get();
        $export = PortalExport::query()->create([
            'tenant_id' => $context->tenantId,
            'requested_by' => $user->public_id,
            'type' => $type,
            'status' => 'queued',
            'filters' => $filters,
        ]);
        $envelope = new TenantJobEnvelope(
            jobId: (string) Str::ulid(),
            schemaVersion: 1,
            tenantId: $context->tenantId,
            actorType: $context->actorType,
            actorId: $context->actorId,
            correlationId: $context->correlationId,
            causationId: (string) $export->getKey(),
        );

        GeneratePortalExport::dispatch((string) $export->getKey(), $envelope)->afterCommit();
        $this->audit->record(new AuditEntry(
            action: 'reporting.portal_export.queued',
            targetType: 'portal_export',
            targetId: (string) $export->getKey(),
            result: AuditResult::Succeeded,
            metadata: ['type' => $type, 'format' => $format],
        ));

        return $export;
    }
}
