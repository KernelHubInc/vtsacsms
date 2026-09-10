<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Models\User;
use App\Modules\Maintenance\Domain\WorkOrderState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class MaintenanceDashboardService
{
    public function __construct(private AccessibleMaintenanceSitesQuery $sites) {}

    /**
     * @return array{
     * open_work_orders:int,sla_breaches:int,downtime_seconds:int,availability_percent:float,
     * mean_time_to_acknowledge_seconds:int,mean_time_to_repair_seconds:int,repeat_failures:int,
     * maintenance_cost_minor:int,parts_consumed_quantity_base:int,warranty_recovery_minor:int,
     * cost_per_asset:list<array{asset_type:string,asset_id:string,cost_minor:int}>,
     * currency:string
     * }
     */
    public function summary(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $siteIds = $this->sites->for($user)->pluck('id');
        $terminal = [WorkOrderState::Closed->value, WorkOrderState::Canceled->value];
        $orders = DB::table('maintenance_work_orders')->whereIn('site_id', $siteIds);
        $periodOrders = (clone $orders)->whereBetween('created_at', [$from, $to]);
        $now = CarbonImmutable::now('UTC');
        $open = (clone $orders)->whereNotIn('state', $terminal)->count();
        $breaches = (clone $orders)
            ->whereNotIn('state', $terminal)
            ->whereNotNull('resolve_target_at')
            ->where('resolve_target_at', '<', $now)
            ->count();
        $downtime = DB::table('maintenance_downtime_periods')
            ->whereIn('site_id', $siteIds)
            ->where('started_at', '<', $to)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get(['started_at', 'ended_at'])
            ->sum(function (object $period) use ($from, $to): int {
                $start = max(CarbonImmutable::parse((string) $period->started_at), $from);
                $end = min(
                    $period->ended_at === null ? $to : CarbonImmutable::parse((string) $period->ended_at),
                    $to,
                );

                return max(0, (int) $start->diffInSeconds($end));
            });
        $assetCount = DB::table('charging_stations')->whereIn('site_id', $siteIds)->count()
            + DB::table('evses as evse')->join('charging_stations as station', 'station.id', '=', 'evse.charging_station_id')
                ->whereIn('station.site_id', $siteIds)->count()
            + DB::table('connectors as connector')->join('evses as evse', 'evse.id', '=', 'connector.evse_id')
                ->join('charging_stations as station', 'station.id', '=', 'evse.charging_station_id')
                ->whereIn('station.site_id', $siteIds)->count();
        $capacity = max(1, $assetCount * max(1, (int) $from->diffInSeconds($to)));
        $ackRows = (clone $periodOrders)->whereNotNull('acknowledged_at')->get(['created_at', 'acknowledged_at']);
        $repairRows = (clone $periodOrders)->whereNotNull('started_at')->whereNotNull('verified_at')
            ->get(['started_at', 'verified_at']);
        $costPerAsset = array_values((clone $periodOrders)
            ->groupBy(['asset_type', 'asset_id'])
            ->orderByDesc(DB::raw('SUM(actual_cost_minor)'))
            ->limit(20)
            ->get([
                'asset_type',
                'asset_id',
                DB::raw('SUM(actual_cost_minor) AS cost_minor'),
            ])
            ->map(static fn (object $row): array => [
                'asset_type' => (string) $row->asset_type,
                'asset_id' => (string) $row->asset_id,
                'cost_minor' => (int) $row->cost_minor,
            ])->all());
        $orderIds = (clone $periodOrders)->pluck('id');
        $parts = (int) DB::table('inventory_stock_movements')
            ->where('reference_type', 'maintenance_work_order')
            ->whereIn('reference_id', $orderIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'work_order_issue' THEN quantity_base WHEN movement_type = 'work_order_return' THEN -quantity_base ELSE 0 END), 0) AS quantity")
            ->value('quantity');
        $recovery = (int) DB::table('maintenance_rmas')->whereIn('work_order_id', $orderIds)->sum('recovered_minor');
        $currency = (string) ((clone $periodOrders)->latest('created_at')->value('currency') ?? 'PHP');

        return [
            'open_work_orders' => $open,
            'sla_breaches' => $breaches,
            'downtime_seconds' => (int) $downtime,
            'availability_percent' => round(max(0, 1 - ($downtime / $capacity)) * 100, 2),
            'mean_time_to_acknowledge_seconds' => $this->meanDuration(array_values($ackRows->all()), 'created_at', 'acknowledged_at'),
            'mean_time_to_repair_seconds' => $this->meanDuration(array_values($repairRows->all()), 'started_at', 'verified_at'),
            'repeat_failures' => DB::table('maintenance_incidents')->whereIn('site_id', $siteIds)
                ->whereBetween('first_observed_at', [$from, $to])->where('occurrence_count', '>', 1)->count(),
            'maintenance_cost_minor' => (int) (clone $periodOrders)->sum('actual_cost_minor'),
            'parts_consumed_quantity_base' => $parts,
            'warranty_recovery_minor' => $recovery,
            'cost_per_asset' => $costPerAsset,
            'currency' => mb_strtoupper($currency),
        ];
    }

    /** @param list<object> $rows */
    private function meanDuration(array $rows, string $from, string $to): int
    {
        if ($rows === []) {
            return 0;
        }
        $total = array_sum(array_map(
            static fn (object $row): int => max(
                0,
                (int) CarbonImmutable::parse((string) $row->{$from})
                    ->diffInSeconds(CarbonImmutable::parse((string) $row->{$to})),
            ),
            $rows,
        ));

        return (int) round($total / count($rows));
    }
}
