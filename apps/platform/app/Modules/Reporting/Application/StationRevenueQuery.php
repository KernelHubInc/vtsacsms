<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class StationRevenueQuery
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AuthorizationService $authorization,
        private CurrentTenant $tenant,
    ) {}

    /**
     * @return array{
     *     totals: array<string, int>,
     *     stations: list<array{id: string, name: string, site: string, amounts: list<array{currency: string, amount_minor: int, sessions: int}>}>,
     *     page: int, pages: int, count: int
     * }|null
     */
    public function read(User $user, CarbonImmutable $from, CarbonImmutable $now, string $siteId, int $page): ?array
    {
        if (! $this->authorization->holdsAnywhere($user, PermissionKey::PaymentView)) {
            return null;
        }

        $tenant = $this->tenant->get()->tenantId;
        $siteScope = DB::table('sites')->where('tenant_id', $tenant)->select('id');
        $tenantWide = true;
        foreach ([PermissionKey::LocationView, PermissionKey::ReportingView, PermissionKey::AssetView, PermissionKey::PaymentView] as $permission) {
            if (! $this->authorization->allows($user, $permission)) {
                $tenantWide = false;
                $siteScope->whereIn('id', $this->sites->for($user, $permission, includeHostedSites: false)->select('id'));
            }
        }
        if ($siteId !== '') {
            $siteScope->where('id', $siteId);
        }

        $rows = DB::table('charging_sessions as session')
            ->join('charging_stations as station', function ($join): void {
                $join->on('station.id', '=', 'session.charging_station_id')
                    ->on('station.tenant_id', '=', 'session.tenant_id')
                    ->on('station.site_id', '=', 'session.site_id');
            })
            ->join('sites as site', fn ($join) => $join->on('site.id', '=', 'station.site_id')->on('site.tenant_id', '=', 'station.tenant_id'))
            ->where('session.tenant_id', $tenant)
            ->whereIn('station.site_id', $siteScope)
            // A scoped operator must not inherit a previous operator's sales when a site changes hands.
            ->when(! $tenantWide, fn ($query) => $query->whereColumn('session.operator_id', 'site.operator_organization_id'))
            ->where('session.state', 'completed')->whereBetween('session.stopped_at', [$from, $now])
            ->whereNotNull('session.currency')->whereNotNull('session.final_cost_minor')
            ->select('station.id', 'station.name', 'site.name as site_name', 'session.currency')
            ->selectRaw('SUM(session.final_cost_minor - COALESCE(session.platform_fee_minor, 0)) AS amount_minor, COUNT(*) AS session_count')
            ->groupBy('station.id', 'station.name', 'site.name', 'session.currency')
            ->orderBy('station.name')->orderBy('station.id')->orderBy('session.currency')->get();

        $totals = [];
        $stations = [];
        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $amount = (int) $row->amount_minor;
            $totals[$currency] = ($totals[$currency] ?? 0) + $amount;
            $id = (string) $row->id;
            $stations[$id] ??= ['id' => $id, 'name' => (string) $row->name, 'site' => (string) $row->site_name, 'amounts' => []];
            $stations[$id]['amounts'][] = ['currency' => $currency, 'amount_minor' => $amount, 'sessions' => (int) $row->session_count];
        }

        ksort($totals);
        $pages = max(1, (int) ceil(count($stations) / 8));
        $page = min(max(1, $page), $pages);

        return ['totals' => $totals, 'stations' => array_slice(array_values($stations), ($page - 1) * 8, 8),
            'page' => $page, 'pages' => $pages, 'count' => count($stations)];
    }
}
