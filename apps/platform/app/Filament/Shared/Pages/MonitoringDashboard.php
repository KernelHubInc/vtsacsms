<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Reporting\Application\MonitoringDashboardQuery;
use Filament\Pages\Dashboard;
use Livewire\Attributes\Url;

abstract class MonitoringDashboard extends Dashboard
{
    protected string $view = 'filament.shared.pages.monitoring-dashboard';

    #[Url]
    public int $days = 7;

    #[Url]
    public string $site = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public int $stationPage = 1;

    public function updated(string $property): void
    {
        if (in_array($property, ['site', 'search', 'status'], true)) {
            $this->stationPage = 1;
        }
    }

    public function nextStations(): void
    {
        $this->stationPage++;
    }

    public function previousStations(): void
    {
        $this->stationPage = max(1, $this->stationPage - 1);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $authorized = app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ReportingView);

        return ['authorized' => $authorized, 'dashboard' => $authorized
            ? app(MonitoringDashboardQuery::class)->read($user, $this->days, $this->site, mb_substr($this->search, 0, 100), $this->status, $this->stationPage)
            : null];
    }
}
