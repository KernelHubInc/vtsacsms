<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Domain\Models\PortalExport;
use BackedEnum;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ReportingView);
    }

    public function queueExport(string $type): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(PortalExportService::class)->queue($user, $type);
        Notification::make()
            ->title('Export queued')
            ->body('The CSV will appear below after the tenant-aware background job completes.')
            ->success()
            ->send();
    }

    public function downloadExport(string $id): StreamedResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $export = PortalExport::query()->whereKey($id)->where('requested_by', $user->public_id)->firstOrFail();
        abort_unless($export->status === 'completed' && $export->disk !== null && $export->path !== null, 404);

        return Storage::disk($export->disk)->download(
            $export->path,
            sprintf('power-solutions-%s-%s.csv', $export->type, $export->getKey()),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'canExport' => app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ReportingExport),
            'exports' => PortalExport::query()
                ->where('requested_by', $user->public_id)
                ->latest()
                ->limit(25)
                ->get(),
        ];
    }
}
