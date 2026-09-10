<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
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
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, scalar|null> $filters */
    public function queue(User $user, string $type, array $filters = []): PortalExport
    {
        if (! $this->authorization->holdsAnywhere($user, PermissionKey::ReportingExport)) {
            throw new DomainException('You are not authorized to export reports.');
        }
        if (! in_array($type, ['sessions', 'assets', 'finance'], true)) {
            throw new DomainException('Unsupported report export type.');
        }

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
            metadata: ['type' => $type],
        ));

        return $export;
    }
}
