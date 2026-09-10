<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Reporting\Application\PortalDashboardQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

abstract class PortalStatsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $metrics = app(PortalDashboardQuery::class)->summary($user);

        return [
            Stat::make('Network availability', $metrics['network_availability_percent'].'%')
                ->description($metrics['available_connectors'].' currently available')
                ->color('success'),
            Stat::make('Online / offline chargers', $metrics['online_chargers'].' / '.$metrics['offline_chargers'])
                ->description($metrics['stale_connectors'].' stale connector signals')
                ->color($metrics['offline_connectors'] > 0 ? 'warning' : 'success'),
            Stat::make('Active sessions', (string) $metrics['active_sessions'])
                ->description($metrics['connectors'].' connectors across '.$metrics['sites'].' sites')
                ->color('info'),
            Stat::make('Energy delivered · 30 days', number_format($metrics['energy_wh'] / 1000, 1).' kWh')
                ->description('Source value retained in watt-hours'),
            Stat::make('Gross revenue · 30 days', $metrics['currency'].' '.number_format($metrics['gross_revenue_minor'] / 100, 2))
                ->description('Finalized session cost; integer minor units'),
            Stat::make('Payment success', $metrics['payment_success_percent'].'%')
                ->description('Terminal payment outcomes · 30 days'),
            Stat::make('Connector utilization', $metrics['utilization_percent'].'%')
                ->description('Charging seconds / connector capacity · 30 days'),
            Stat::make('Open faults', (string) $metrics['fault_count'])
                ->description('Current connector status snapshot')
                ->color($metrics['fault_count'] > 0 ? 'danger' : 'success'),
            Stat::make('Mean time to repair', 'Module pending')
                ->description('Maintenance records are not implemented yet')
                ->color('gray'),
            Stat::make('Inventory alerts', (string) $metrics['inventory_alerts'])
                ->description('Accessible items at or below their reorder point')
                ->color($metrics['inventory_alerts'] > 0 ? 'warning' : 'success'),
            Stat::make('Maintenance SLA breaches', 'Module pending')
                ->description('No synthetic SLA values are shown')
                ->color('gray'),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ReportingView);
    }
}
