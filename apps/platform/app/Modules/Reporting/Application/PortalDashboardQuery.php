<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Inventory\Application\InventoryReportService;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class PortalDashboardQuery
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AccessibleChargingSessionsQuery $sessions,
        private InventoryReportService $inventory,
        private CurrentTenant $tenant,
    ) {}

    /**
     * @return array{
     *   sites:int,chargers:int,online_chargers:int,offline_chargers:int,connectors:int,available_connectors:int,offline_connectors:int,
     *   stale_connectors:int,fault_count:int,active_sessions:int,energy_wh:int,gross_revenue_minor:int,
     *   currency:string,payment_success_percent:float,utilization_percent:float,network_availability_percent:float,
     *   mttr_seconds:null,inventory_alerts:int,maintenance_sla_breaches:null
     * }
     */
    public function summary(User $user): array
    {
        $siteIds = $this->siteIds($user);
        $key = $this->cacheKey('summary', $user, $siteIds);

        /** @var array<string, int|float|string|null> $summary */
        $summary = Cache::remember($key, now()->addSeconds(60), function () use ($user, $siteIds): array {
            $periodStart = CarbonImmutable::now('UTC')->subDays(30);
            $sessions = $this->sessions->for($user);
            $sessionIds = (clone $sessions)->where('requested_at', '>=', $periodStart)->pluck('id');
            $activeStates = array_map(
                static fn (ChargingSessionState $state): string => $state->value,
                [
                    ChargingSessionState::Requested,
                    ChargingSessionState::Authorizing,
                    ChargingSessionState::Authorized,
                    ChargingSessionState::Starting,
                    ChargingSessionState::Charging,
                    ChargingSessionState::SuspendedByEv,
                    ChargingSessionState::SuspendedByEvse,
                    ChargingSessionState::Stopping,
                    ChargingSessionState::Finalizing,
                    ChargingSessionState::ReviewRequired,
                ],
            );
            $connectorSignals = $this->connectorSignals($siteIds);
            $now = CarbonImmutable::now('UTC');
            $stale = $connectorSignals->filter(
                static fn (array $signal): bool => CarbonImmutable::parse($signal['observed_at'])
                    ->addSeconds($signal['stale_after_seconds'])
                    ->isBefore($now),
            )->count();
            $freshSignalRows = $connectorSignals->reject(
                static fn (array $signal): bool => CarbonImmutable::parse($signal['observed_at'])
                    ->addSeconds($signal['stale_after_seconds'])
                    ->isBefore($now),
            );
            $freshSignals = $freshSignalRows->count();
            $available = $freshSignalRows->whereIn('status', ['available', 'occupied', 'reserved'])->count();
            $faults = $connectorSignals->where('status', 'faulted')->count();
            $offline = $connectorSignals->where('status', 'offline')->count();
            $connectorCount = DB::table('connectors as connector')
                ->join('evses as evse', 'evse.id', '=', 'connector.evse_id')
                ->join('charging_stations as station', 'station.id', '=', 'evse.charging_station_id')
                ->whereIn('station.site_id', $siteIds)
                ->count();
            $chargerCount = DB::table('charging_stations')->whereIn('site_id', $siteIds)->count();
            $offlineChargers = $connectorSignals
                ->groupBy('station_id')
                ->filter(function (Collection $signals) use ($now): bool {
                    return $signals->isNotEmpty() && $signals->every(
                        static fn (array $signal): bool => $signal['status'] === 'offline'
                            || CarbonImmutable::parse($signal['observed_at'])
                                ->addSeconds($signal['stale_after_seconds'])
                                ->isBefore($now),
                    );
                })
                ->count();
            $activeSessions = (clone $sessions)->whereIn('state', $activeStates)->count();
            $energyWh = (int) (clone $sessions)->where('requested_at', '>=', $periodStart)->sum('energy_wh');
            $grossRevenue = (int) (clone $sessions)
                ->where('requested_at', '>=', $periodStart)
                ->whereNotNull('final_cost_minor')
                ->sum('final_cost_minor');
            $durationSeconds = (int) (clone $sessions)->where('requested_at', '>=', $periodStart)->sum('duration_seconds');
            $payments = PaymentIntent::query()
                ->where('billable_type', ChargingSession::class)
                ->whereIn('billable_id', $sessionIds);
            $paymentTotal = (clone $payments)->whereIn('state', [
                'captured', 'partially_captured', 'partially_refunded', 'refunded', 'failed', 'canceled', 'expired',
            ])->count();
            $paymentSuccessful = (clone $payments)->whereIn('state', [
                'captured', 'partially_captured', 'partially_refunded', 'refunded',
            ])->count();
            $currency = (string) ((clone $sessions)->whereNotNull('currency')->latest('requested_at')->value('currency') ?? 'PHP');
            $capacitySeconds = max(1, $connectorCount * 30 * 86400);

            return [
                'sites' => count($siteIds),
                'chargers' => $chargerCount,
                'online_chargers' => max(0, $chargerCount - $offlineChargers),
                'offline_chargers' => $offlineChargers,
                'connectors' => $connectorCount,
                'available_connectors' => $available,
                'offline_connectors' => $offline,
                'stale_connectors' => $stale,
                'fault_count' => $faults,
                'active_sessions' => $activeSessions,
                'energy_wh' => $energyWh,
                'gross_revenue_minor' => $grossRevenue,
                'currency' => strtoupper($currency),
                'payment_success_percent' => $paymentTotal === 0 ? 0.0 : round($paymentSuccessful / $paymentTotal * 100, 1),
                'utilization_percent' => round(min(100, $durationSeconds / $capacitySeconds * 100), 1),
                'network_availability_percent' => $freshSignals === 0 ? 0.0 : round($available / $freshSignals * 100, 1),
                'mttr_seconds' => null,
                'inventory_alerts' => $this->inventory->reorderAlerts($user)->count(),
                'maintenance_sla_breaches' => null,
            ];
        });

        /** @var array{
         *   sites:int,chargers:int,online_chargers:int,offline_chargers:int,connectors:int,available_connectors:int,offline_connectors:int,
         *   stale_connectors:int,fault_count:int,active_sessions:int,energy_wh:int,gross_revenue_minor:int,
         *   currency:string,payment_success_percent:float,utilization_percent:float,network_availability_percent:float,
         *   mttr_seconds:null,inventory_alerts:int,maintenance_sla_breaches:null
         * } $summary
         */
        return $summary;
    }

    /**
     * @return list<array{
     *   id:string,site_name:string,address:string,latitude:float,longitude:float,status:string,
     *   chargers:int,connectors:int,faults:int,offline:int,stale:int,active_sessions:int
     * }>
     */
    public function map(User $user): array
    {
        $siteIds = $this->siteIds($user);
        $key = $this->cacheKey('map', $user, $siteIds);

        /** @var list<array<string, int|float|string>> $markers */
        $markers = Cache::remember($key, now()->addSeconds(30), function () use ($siteIds): array {
            $sites = DB::table('sites')
                ->whereIn('id', $siteIds)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('name')
                ->get(['id', 'name', 'address_line_1', 'latitude', 'longitude']);
            $assetCounts = DB::table('charging_stations as station')
                ->leftJoin('evses as evse', 'evse.charging_station_id', '=', 'station.id')
                ->leftJoin('connectors as connector', 'connector.evse_id', '=', 'evse.id')
                ->whereIn('station.site_id', $siteIds)
                ->groupBy('station.site_id')
                ->selectRaw('station.site_id, COUNT(DISTINCT station.id) AS chargers, COUNT(DISTINCT connector.id) AS connectors')
                ->get()
                ->keyBy('site_id');
            $signalRows = $this->connectorSignals($siteIds);
            $signalsBySite = $signalRows->groupBy('site_id');
            $activeStates = ['requested', 'authorizing', 'authorized', 'starting', 'charging', 'suspended_by_ev', 'suspended_by_evse', 'stopping'];
            $active = DB::table('charging_sessions')
                ->whereIn('site_id', $siteIds)
                ->whereIn('state', $activeStates)
                ->groupBy('site_id')
                ->selectRaw('site_id, COUNT(*) AS total')
                ->pluck('total', 'site_id');
            $now = CarbonImmutable::now('UTC');

            return $sites->map(function (object $site) use ($assetCounts, $signalsBySite, $active, $now): array {
                $signals = $signalsBySite->get($site->id, collect());
                $stale = $signals->filter(
                    static fn (array $signal): bool => CarbonImmutable::parse($signal['observed_at'])
                        ->addSeconds($signal['stale_after_seconds'])
                        ->isBefore($now),
                )->count();
                $faults = $signals->where('status', 'faulted')->count();
                $offline = $signals->where('status', 'offline')->count();
                $activeSessions = (int) ($active[$site->id] ?? 0);
                $status = match (true) {
                    $faults > 0 => 'faulted',
                    $activeSessions > 0 => 'busy',
                    $stale > 0 => 'stale',
                    $offline > 0 => 'offline',
                    $signals->where('status', 'available')->isNotEmpty() => 'available',
                    default => 'unknown',
                };
                $assets = $assetCounts->get($site->id);

                return [
                    'id' => (string) $site->id,
                    'site_name' => (string) $site->name,
                    'address' => (string) ($site->address_line_1 ?? ''),
                    'latitude' => (float) $site->latitude,
                    'longitude' => (float) $site->longitude,
                    'status' => $status,
                    'availability' => $status,
                    'chargers' => (int) ($assets->chargers ?? 0),
                    'connectors' => (int) ($assets->connectors ?? 0),
                    'faults' => $faults,
                    'offline' => $offline,
                    'stale' => $stale,
                    'active_sessions' => $activeSessions,
                ];
            })->values()->all();
        });

        /** @var list<array{
         *   id:string,site_name:string,address:string,latitude:float,longitude:float,status:string,
         *   chargers:int,connectors:int,faults:int,offline:int,stale:int,active_sessions:int
         * }> $markers
         */
        return $markers;
    }

    /** @return list<string> */
    private function siteIds(User $user): array
    {
        return array_values($this->sites->for($user)->orderBy('id')->pluck('id')->map(
            static fn (mixed $id): string => (string) $id,
        )->all());
    }

    /**
     * @param  list<string>  $siteIds
     * @return Collection<int, array{site_id:string,station_id:string,status:string,observed_at:string,stale_after_seconds:int}>
     */
    private function connectorSignals(array $siteIds): Collection
    {
        return DB::table('charging_connector_statuses as signal')
            ->join('connectors as connector', 'connector.id', '=', 'signal.connector_id')
            ->join('evses as evse', 'evse.id', '=', 'connector.evse_id')
            ->join('charging_stations as station', 'station.id', '=', 'evse.charging_station_id')
            ->whereIn('station.site_id', $siteIds)
            ->get(['station.site_id', 'station.id as station_id', 'signal.status', 'signal.observed_at', 'signal.stale_after_seconds'])
            ->map(static fn (object $row): array => [
                'site_id' => (string) $row->site_id,
                'station_id' => (string) $row->station_id,
                'status' => (string) $row->status,
                'observed_at' => (string) $row->observed_at,
                'stale_after_seconds' => (int) $row->stale_after_seconds,
            ]);
    }

    /** @param list<string> $siteIds */
    private function cacheKey(string $type, User $user, array $siteIds): string
    {
        return sprintf(
            'portal:%s:%s:%s:%s',
            $type,
            $this->tenant->get()->tenantId,
            (string) $user->getKey(),
            hash('sha256', implode('|', $siteIds)),
        );
    }
}
