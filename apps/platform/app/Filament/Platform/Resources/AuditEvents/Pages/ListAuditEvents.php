<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditEvents\Pages;

use App\Filament\Platform\Resources\AuditEvents\AuditEventResource;
use App\Filament\Platform\Resources\AuditEvents\AuditFilters;
use App\Models\User;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Application\PortalReportAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    public function getSubheading(): string
    {
        return 'Read-only recorded activity. Times are UTC. Older records may have fewer details; omitted activity cannot be reconstructed.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportActivity')->label('Export activity')
            ->visible(fn (): bool => auth()->user() instanceof User && app(PortalReportAccess::class)->canExport(auth()->user(), 'audit'))
            ->modalDescription('Starts with your applied filters. PDF shows up to 200 records; Excel contains all matching rows. Download from Reports → Queued exports.')
            ->fillForm(fn (): array => ($this->getTableFilterState('activity') ?? []) + ['format' => 'xlsx'])
            ->schema([...AuditFilters::fields(), Select::make('format')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF'])->required()])
            ->action(function (array $data): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                app(PortalExportService::class)->queue($user, 'audit', $data, $data['format']);
                Notification::make()->title('Export queued')->body('Download it from Reports → Queued exports when ready.')->success()->send();
            }),
            Action::make('downloads')->label('Queued exports')->url('/admin/reports')
                ->visible(fn (): bool => auth()->user() instanceof User && app(PortalReportAccess::class)->canExport(auth()->user(), 'audit')),
        ];
    }
}
