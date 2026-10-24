<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Models\User;
use App\Modules\Reporting\Application\PortalExportRowsQuery;
use App\Modules\Reporting\Application\PortalPdfReport;
use App\Modules\Reporting\Application\PortalReportAccess;
use App\Modules\Reporting\Domain\Models\PortalExport;
use App\Modules\Tenancy\Application\Queue\TenantAwareJob;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use App\Modules\Tenancy\Application\Queue\UseTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class GeneratePortalExport implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $exportId,
        public readonly TenantJobEnvelope $envelope,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(UseTenantContext::class)];
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    public function handle(PortalExportRowsQuery $rows, PortalReportAccess $access): void
    {
        $export = PortalExport::query()->findOrFail($this->exportId);
        $user = User::query()->where('public_id', $export->requested_by)->firstOrFail();
        if (! $access->canExport($user, $export->type)) {
            $export->update(['status' => 'failed', 'failure_code' => 'authorization_revoked']);

            return;
        }

        $format = $export->filters['_format'] ?? 'csv';
        if (! in_array($format, ['csv', 'pdf'], true)) {
            $export->update(['status' => 'failed', 'failure_code' => 'unsupported_format']);

            return;
        }
        $filters = $export->filters;
        $filters['_scope_hash'] = $access->scopeFingerprint($user, $export->type);
        $export->update(['status' => 'processing', 'failure_code' => null, 'filters' => $filters]);
        $report = $rows->for($export->type, $user, $export->filters);
        $disk = (string) config('filesystems.default', 'local');
        if ($format === 'pdf') {
            $result = app(PortalPdfReport::class)->render($export->type, $report, $filters);
            $path = sprintf('portal-exports/%s/%s.pdf', $this->envelope->tenantId, $export->getKey());
            if (! Storage::disk($disk)->put($path, $result['bytes'])) {
                throw new RuntimeException('Unable to persist PDF export.');
            }
            $export->update([
                'status' => 'completed',
                'disk' => $disk,
                'path' => $path,
                'row_count' => $result['count'],
                'completed_at' => now('UTC'),
            ]);

            return;
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate export stream.');
        }

        $count = 0;
        fputcsv($stream, $report['headers']);
        foreach ($report['rows'] as $row) {
            fputcsv($stream, array_map($this->safeCell(...), $row));
            $count++;
        }
        rewind($stream);
        $path = sprintf('portal-exports/%s/%s.csv', $this->envelope->tenantId, $export->getKey());
        $written = Storage::disk($disk)->writeStream($path, $stream);
        fclose($stream);
        if (! $written) {
            throw new RuntimeException('Unable to persist export.');
        }

        $export->update([
            'status' => 'completed',
            'disk' => $disk,
            'path' => $path,
            'row_count' => $count,
            'completed_at' => now('UTC'),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        PortalExport::withoutGlobalScopes()
            ->where('tenant_id', $this->envelope->tenantId)
            ->whereKey($this->exportId)
            ->update([
                'status' => 'failed',
                'failure_code' => 'generation_failed',
            ]);
    }

    private function safeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'".$value : $value;
    }
}
