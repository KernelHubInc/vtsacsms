<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Application\StationConnectionQuery;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only Reporting composition; all source records remain owned by their modules. */
final readonly class MonitoringDashboardQuery
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AccessibleChargingSessionsQuery $sessions,
        private AuthorizationService $authorization,
        private CurrentTenant $tenant,
        private StationConnectionQuery $connections,
    ) {}

    /** @return array<string, mixed> */
    public function read(User $user, int $days = 7, string $siteId = '', string $search = '', string $status = '', int $page = 1): array
    {
        abort_unless($this->authorization->holdsAnywhere($user, PermissionKey::ReportingView), 403);
        abort_unless(in_array($days, [7, 30, 90], true), 422);
        $tenant = $this->tenant->get()->tenantId;
        $now = CarbonImmutable::now('UTC');
        $from = $now->setTimezone('Asia/Manila')->startOfDay()->subDays($days - 1)->utc();
        $sites = $this->sites->for($user)->whereIn('id', $this->sites->for($user, PermissionKey::ReportingView)->select('id'))
            ->where('is_active', true)->where('lifecycle_status', '!=', 'retired')
            ->orderBy('name')->get(['id', 'name', 'address_line_1', 'timezone']);
        abort_if($siteId !== '' && ! $sites->contains('id', $siteId), 403);
        $siteIds = $sites->when($siteId !== '', fn ($rows) => $rows->where('id', $siteId))->pluck('id')->all();
        $assetSiteIds = $this->sites->for($user, PermissionKey::AssetView)->whereIn('id', $siteIds)->pluck('id')->all();
        $stations = DB::table('charging_stations')->where('tenant_id', $tenant)->whereIn('site_id', $assetSiteIds)
            ->orderBy('name')->get(['id', 'site_id', 'name', 'charge_point_identity', 'lifecycle_status']);
        $stationIds = array_values($stations->map(static fn (object $station): string => (string) $station->id)->all());
        $connections = $this->connections->forStations($stationIds);
        $connectors = DB::table('connectors as c')
            ->join('evses as e', fn ($join) => $join->on('e.id', '=', 'c.evse_id')->on('e.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $tenant)->whereIn('e.charging_station_id', $stationIds)
            ->get(['c.id', 'c.connector_number', 'c.lifecycle_status', 'c.maximum_power_w', 'e.charging_station_id', 'e.lifecycle_status as evse_lifecycle']);
        $states = $this->connections->connectorStates(connectorIds: array_values($connectors->map(static fn (object $connector): string => (string) $connector->id)->all()));
        $breakdown = array_fill_keys(['Available', 'Preparing', 'Charging', 'Paused by vehicle', 'Paused by charger', 'Finishing', 'Reserved', 'In use', 'Unavailable', 'Faulted', 'Offline', 'Unknown', 'Maintenance', 'Draft', 'Retired'], 0);
        $byStation = $connectors->groupBy('charging_station_id');
        $lifecycle = ['active' => 0, 'maintenance' => 0, 'draft' => 0, 'retired' => 0];
        $online = 0;
        $attention = [];
        $cards = [];
        foreach ($stations as $station) {
            $lifecycle[$station->lifecycle_status]++;
            $connection = $connections[$station->id] ?? null;
            $connectionStatus = $connection['status'] ?? 'unknown';
            if ($station->lifecycle_status === 'active' && $connectionStatus === 'online') {
                $online++;
            }
            $site = $sites->firstWhere('id', $station->site_id);
            $plugs = [];
            foreach ($byStation->get($station->id, collect()) as $connector) {
                $label = $connectionStatus === 'online'
                    ? ($states[$connector->id]['label'] ?? 'Unknown')
                    : ($connectionStatus === 'offline' ? 'Offline' : 'Unknown');
                foreach ([$station->lifecycle_status, $connector->evse_lifecycle, $connector->lifecycle_status] as $state) {
                    if ($state !== 'active') {
                        $label = ucfirst($state);
                        break;
                    }
                }
                if (! array_key_exists($label, $breakdown)) {
                    $label = 'Unknown';
                }
                $breakdown[$label]++;
                $plugs[] = ['number' => (int) $connector->connector_number, 'status' => $label, 'power_w' => (int) $connector->maximum_power_w];
                if ($label === 'Faulted') {
                    $attention[] = ['kind' => 'Connector fault', 'name' => $station->name.' · connector '.$connector->connector_number, 'station_id' => $station->id];
                }
            }
            if ($station->lifecycle_status === 'active' && $connectionStatus !== 'online') {
                $attention[] = ['kind' => $connectionStatus === 'offline' ? 'Charger offline' : 'No connection evidence', 'name' => $station->name, 'station_id' => $station->id];
            }
            $cards[] = ['id' => $station->id, 'name' => $station->name, 'identity' => $station->charge_point_identity,
                'site' => $site?->name, 'address' => $site?->address_line_1, 'lifecycle' => $station->lifecycle_status,
                'connection' => $connectionStatus, 'last_seen' => $connection['last_seen_at'] ?? null, 'connectors' => $plugs];
        }
        $stationCount = count($cards);
        usort($attention, static fn (array $left, array $right): int => ($left['kind'] === 'Connector fault' ? 0 : 1) <=> ($right['kind'] === 'Connector fault' ? 0 : 1));
        $filtered = collect($cards)->filter(static fn (array $card): bool => ($search === '' || str_contains(mb_strtolower($card['name'].' '.$card['identity'].' '.$card['site']), mb_strtolower($search)))
            && ($status === '' || $card['connection'] === $status || $card['lifecycle'] === $status));
        $pages = max(1, (int) ceil($filtered->count() / 8));
        $page = min(max(1, $page), $pages);
        $sessions = $this->sessions->for($user)->whereIn('site_id', $siteIds);
        $sessionStates = (clone $sessions)->groupBy('state')->selectRaw('state, COUNT(*) AS total')->pluck('total', 'state')->all();
        $liveSessions = (clone $sessions)->where('state', 'charging')->orderByDesc('started_at')->limit(4)
            ->get(['id', 'charge_point_identity', 'energy_wh', 'started_at'])
            ->map(static fn (ChargingSession $session): array => [
                'id' => $session->getKey(),
                'station' => $session->charge_point_identity,
                'energy_wh' => (int) $session->energy_wh,
                'started_at' => $session->started_at?->toIso8601String(),
            ])->all();
        $completed = (clone $sessions)->where('state', 'completed')->whereBetween('stopped_at', [$from, $now]);
        $siteNames = $sites->pluck('name', 'id');
        $topSites = (clone $completed)->selectRaw('site_id, SUM(energy_wh) AS energy_wh')
            ->groupBy('site_id')->orderByDesc('energy_wh')->limit(3)->toBase()->get()
            ->map(static fn (object $row): array => [
                'name' => (string) ($siteNames->get($row->site_id) ?? 'Site'),
                'energy_wh' => (int) $row->energy_wh,
            ])->all();
        $dateSql = DB::connection()->getDriverName() === 'pgsql'
            ? "DATE(stopped_at AT TIME ZONE 'Asia/Manila')" : "DATE(stopped_at, '+8 hours')";
        $daily = (clone $completed)->selectRaw($dateSql.' AS day, COUNT(*) AS sessions, SUM(energy_wh) AS energy_wh')
            ->groupByRaw($dateSql)->toBase()->get()->keyBy('day');
        $trend = [];
        for ($day = $from->setTimezone('Asia/Manila'); $day->lte($now->setTimezone('Asia/Manila')); $day = $day->addDay()) {
            $row = $daily->get($day->toDateString());
            $trend[] = ['day' => $day->toDateString(), 'sessions' => (int) ($row->sessions ?? 0), 'energy_wh' => (int) ($row->energy_wh ?? 0)];
        }
        $finance = $this->sessions->for($user, PermissionKey::PaymentView)->whereIn('site_id', $siteIds);
        $charges = (clone $finance)->where('state', 'completed')->whereBetween('stopped_at', [$from, $now])
            ->whereNotNull('currency')->whereNotNull('final_cost_minor')->groupBy('currency')
            ->selectRaw('currency, SUM(final_cost_minor) AS amount_minor')->toBase()->get()->map(fn ($row): array => ['currency' => $row->currency, 'amount_minor' => (int) $row->amount_minor])->all();
        $dailyCharges = (clone $finance)->where('state', 'completed')->whereBetween('stopped_at', [$from, $now])
            ->whereNotNull('currency')->whereNotNull('final_cost_minor')->groupByRaw($dateSql)->groupBy('currency')
            ->selectRaw($dateSql.' AS day, currency, SUM(final_cost_minor) AS amount_minor')->toBase()->get()->groupBy('day');
        foreach ($trend as &$point) {
            $point['charges'] = $dailyCharges->get($point['day'], collect())->map(fn ($row): array => ['currency' => $row->currency, 'amount_minor' => (int) $row->amount_minor])->all();
        }
        unset($point);
        // Wallet funding has no station attribution. Site-only grants must not reveal tenant totals.
        $walletVisible = $siteId === '' && $this->authorization->allows($user, PermissionKey::PaymentView)
            && $this->authorization->allows($user, PermissionKey::ReportingView);
        $topups = $walletVisible ? DB::table('prepaid_entries as entry')
            ->join('prepaid_accounts as account', fn ($join) => $join->on('account.id', '=', 'entry.account_id')->on('account.tenant_id', '=', 'entry.tenant_id'))
            ->where('entry.tenant_id', $tenant)->where('account.book', 'live')->where('entry.kind', 'topup')
            ->whereBetween('entry.created_at', [$from, $now])->groupBy('account.currency')
            ->selectRaw('account.currency, SUM(entry.amount_minor) AS amount_minor')->get()
            ->map(fn ($row): array => ['currency' => $row->currency, 'amount_minor' => (int) $row->amount_minor])->all() : [];
        $waiting = (clone $sessions)->whereIn('state', ['requested', 'authorizing', 'authorized', 'starting'])
            ->where(fn ($query) => $query->where('start_deadline_at', '<', $now)->orWhere('requested_at', '<', $now->subMinutes(5)))->count();
        $review = (int) ($sessionStates['review_required'] ?? 0);
        $paymentExceptions = DB::table('payment_intents')->where('tenant_id', $tenant)
            ->where('billable_type', ChargingSession::class)
            ->whereIn('billable_id', (clone $finance)->select('id'))->whereBetween('created_at', [$from, $now])
            ->whereIn('state', ['failed', 'expired'])->count();
        $maintenanceSites = $this->sites->for($user, PermissionKey::MaintenanceView)->whereIn('id', $siteIds)->pluck('id')->all();
        $work = DB::table('maintenance_work_orders')->where('tenant_id', $tenant)->whereIn('site_id', $maintenanceSites)->whereNotIn('state', ['closed', 'canceled']);

        return ['sites' => $sites->map(fn ($site): array => ['id' => $site->getKey(), 'name' => $site->name])->all(),
            'updated_at' => $now->toIso8601String(), 'from' => $from->setTimezone('Asia/Manila')->toDateString(), 'days' => $days,
            'station_count' => $stationCount, 'lifecycle' => $lifecycle, 'online' => $online, 'not_online' => $lifecycle['active'] - $online,
            'connector_count' => $connectors->count(), 'breakdown' => $breakdown,
            'available_percent' => $connectors->isEmpty() ? null : round($breakdown['Available'] / $connectors->count() * 100, 1),
            'stations' => $filtered->slice(($page - 1) * 8, 8)->values()->all(), 'matches' => $filtered->count(), 'page' => $page, 'pages' => $pages,
            'session_states' => $sessionStates, 'live_sessions' => $liveSessions, 'top_sites' => $topSites,
            'energy_wh' => (int) (clone $completed)->sum('energy_wh'), 'completed_sessions' => (clone $completed)->count(),
            'trend' => $trend, 'charges' => $charges, 'topups' => $topups, 'wallet_visible' => $walletVisible,
            'finance_visible' => $this->authorization->holdsAnywhere($user, PermissionKey::PaymentView),
            'attention_count' => count($attention), 'attention' => array_slice($attention, 0, 10), 'waiting' => $waiting, 'review' => $review,
            'payment_exceptions' => $paymentExceptions, 'open_work_orders' => (clone $work)->count(),
            'sla_breaches' => (clone $work)->whereNotNull('resolve_target_at')->where('resolve_target_at', '<', $now)->count(),
            'maintenance_visible' => $maintenanceSites !== [],
        ];
    }
}
