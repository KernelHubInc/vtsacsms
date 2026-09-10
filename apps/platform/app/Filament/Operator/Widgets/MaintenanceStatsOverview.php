<?php

declare(strict_types=1);

namespace App\Filament\Operator\Widgets;

use App\Models\User;
use App\Modules\Maintenance\Application\MaintenanceDashboardService;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class MaintenanceStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /** @return array<Stat> */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $to = CarbonImmutable::now('UTC');
        $metrics = app(MaintenanceDashboardService::class)->summary($user, $to->subDays(30), $to);

        return [
            Stat::make('Open work orders', (string) $metrics['open_work_orders'])
                ->color($metrics['open_work_orders'] > 0 ? 'warning' : 'success'),
            Stat::make('SLA breaches', (string) $metrics['sla_breaches'])
                ->color($metrics['sla_breaches'] > 0 ? 'danger' : 'success'),
            Stat::make('Availability', $metrics['availability_percent'].'%')
                ->description('Asset downtime adjusted · 30 days'),
            Stat::make('Mean time to repair', $this->duration($metrics['mean_time_to_repair_seconds']))
                ->description('Start through validated repair'),
            Stat::make('Repeat failures', (string) $metrics['repeat_failures'])
                ->color($metrics['repeat_failures'] > 0 ? 'warning' : 'success'),
            Stat::make('Maintenance cost', $metrics['currency'].' '.number_format($metrics['maintenance_cost_minor'] / 100, 2))
                ->description('Labor, travel, parts, and vendor repair'),
            Stat::make('Parts consumed', number_format($metrics['parts_consumed_quantity_base']))
                ->description('Base units net of returns'),
            Stat::make('Warranty recovery', $metrics['currency'].' '.number_format($metrics['warranty_recovery_minor'] / 100, 2)),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::MaintenanceView);
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 3600) {
            return number_format($seconds / 60, 1).' min';
        }

        return number_format($seconds / 3600, 1).' hr';
    }
}
