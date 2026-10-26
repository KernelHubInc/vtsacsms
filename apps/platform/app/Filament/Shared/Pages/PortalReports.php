<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Application\PortalReportAccess;
use App\Modules\Reporting\Domain\Models\PortalExport;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

abstract class PortalReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Queued exports';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.shared.pages.reports';

    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ReportingView);
    }

    public function queueExport(string $type, string $format = 'pdf'): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        try {
            app(PortalExportService::class)->queue($user, $type, array_filter([
                'from' => $this->from,
                'to' => $this->to,
            ]), $format);
        } catch (DomainException $exception) {
            Notification::make()->title('Report not queued')->body($exception->getMessage())->danger()->send();

            return;
        }
        Notification::make()
            ->title('Export queued')
            ->body(strtoupper($format).' will appear below after the background job completes.')
            ->success()
            ->send();
    }

    public function downloadExport(string $id): StreamedResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $export = PortalExport::query()->whereKey($id)->where('requested_by', $user->public_id)->firstOrFail();
        abort_unless($export->status === 'completed' && $export->disk !== null && $export->path !== null, 404);
        abort_if($export->created_at->lt(now('UTC')->subDays(7)), 404);
        $access = app(PortalReportAccess::class);
        abort_unless($access->canExport($user, $export->type), 403);
        $scopeHash = $export->filters['_scope_hash'] ?? null;
        abort_unless(is_string($scopeHash) && hash_equals($scopeHash, $access->scopeFingerprint($user, $export->type)), 403);
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404);
        $format = $export->filters['_format'] ?? 'csv';
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        return Storage::disk($export->disk)->download(
            $export->path,
            sprintf('power-solutions-%s-%s.%s', $export->type, $export->getKey(), $format),
            ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8'],
        );
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $access = app(PortalReportAccess::class);

        return [
            'availableReports' => array_filter(
                PortalReportAccess::titles(),
                fn (string $title, string $type): bool => $access->canExport($user, $type),
                ARRAY_FILTER_USE_BOTH,
            ),
            'exports' => PortalExport::query()
                ->where('requested_by', $user->public_id)
                ->latest()
                ->limit(25)
                ->get(),
        ];
    }
}
