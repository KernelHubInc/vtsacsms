<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Tenancy\Application\CurrentTenant;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Read-only reporting projection across Assets, Charging, Organizations and Identity. */
final readonly class VehicleReportQuery
{
    public function __construct(private CurrentTenant $tenant, private PortalReportAccess $access) {}

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array{headers:list<string>,rows:Generator<int, list<scalar|null>>}
     */
    public function for(string $type, User $user, array $filters): array
    {
        $this->access->assertCanExport($user, $type);
        if ($type === 'vehicles') {
            $query = $this->register($user, $filters);
        } else {
            $query = $this->sessions($user, $type, $filters);
        }

        $columns = match ($type) {
            'vehicles' => [
                'v.id as vehicle_id', 'v.nickname as vehicle', 'v.manufacturer as brand', 'v.model',
                'v.variant', 'owner.name as owner', 'g.subject_id as owner_id',
                'o.name as account', 'g.charging_account_id as account_id',
                DB::raw("COALESCE(o.charging_account_type, 'personal') as account_type"),
                'v.created_at as registered_at_utc',
            ],
            'vehicle_sessions' => [
                's.id as session_id', 'c.vehicle_id', 'v.nickname as vehicle', 'v.manufacturer as brand', 'v.model',
                'driver.name as driver', 'c.subject_id as driver_id', 'account.name as account', 'c.charging_account_id as account_id',
                'site.name as site', 's.site_id', 's.charge_point_identity as charger', 's.connector_id', 's.state',
                's.requested_at as requested_at_utc', 's.started_at as started_at_utc', 's.stopped_at as stopped_at_utc',
                's.energy_wh', 's.duration_seconds', 's.currency', 's.estimated_cost_minor', 's.final_cost_minor',
                'c.payment_state', 'c.settled_at as settled_at_utc', 's.failure_reason',
            ],
            default => [],
        };

        if (in_array($type, ['vehicle_usage', 'vehicle_models'], true)) {
            $groups = $type === 'vehicle_usage'
                ? ['c.vehicle_id', 'v.nickname', 'v.manufacturer', 'v.model', 's.currency']
                : ['v.manufacturer', 'v.model', 's.currency'];
            $query->where('s.state', 'completed')->groupBy($groups);
            foreach ($groups as $group) {
                $query->orderBy($group);
            }
            $columns = [
                ...($type === 'vehicle_usage' ? ['c.vehicle_id', 'v.nickname as vehicle'] : []),
                'v.manufacturer as brand', 'v.model', 's.currency',
                DB::raw('COUNT(*) as sessions'), DB::raw('COUNT(DISTINCT c.vehicle_id) as vehicles'),
                DB::raw('SUM(s.energy_wh) as energy_wh'), DB::raw('SUM(s.duration_seconds) as duration_seconds'),
                DB::raw('SUM(s.final_cost_minor) as final_cost_minor'),
                DB::raw('SUM(CASE WHEN s.final_cost_minor IS NULL THEN 1 ELSE 0 END) as unpriced_sessions'),
                DB::raw('MAX(s.stopped_at) as last_stopped_at_utc'),
            ];
        } else {
            $query->orderBy($type === 'vehicles' ? 'v.id' : 's.id');
        }
        $headers = match ($type) {
            'vehicles' => ['vehicle_id', 'vehicle', 'brand', 'model', 'variant', 'owner', 'owner_id', 'account', 'account_id', 'account_type', 'registered_at_utc'],
            'vehicle_sessions' => ['session_id', 'vehicle_id', 'vehicle', 'brand', 'model', 'driver', 'driver_id', 'account', 'account_id', 'site', 'site_id', 'charger', 'connector_id', 'state', 'requested_at_utc', 'started_at_utc', 'stopped_at_utc', 'energy_wh', 'duration_seconds', 'currency', 'estimated_cost_minor', 'final_cost_minor', 'payment_state', 'settled_at_utc', 'failure_reason'],
            'vehicle_usage' => ['vehicle_id', 'vehicle', 'brand', 'model', 'currency', 'sessions', 'vehicles', 'energy_wh', 'duration_seconds', 'final_cost_minor', 'unpriced_sessions', 'last_stopped_at_utc'],
            'vehicle_models' => ['brand', 'model', 'currency', 'sessions', 'vehicles', 'energy_wh', 'duration_seconds', 'final_cost_minor', 'unpriced_sessions', 'last_stopped_at_utc'],
            default => throw new \InvalidArgumentException('Unsupported vehicle report.'),
        };
        if ($type !== 'vehicles') {
            $headers[] = 'energy_kwh';
        }

        return ['headers' => $headers, 'rows' => $this->rows($query->select($columns), $headers)];
    }

    /** @param array<string, scalar|null> $filters */
    private function register(User $user, array $filters): Builder
    {
        $query = DB::table('driver_vehicles as v')->where('v.tenant_id', $this->tenant->get()->tenantId);
        $this->owners($query);
        // Site-scoped workforce grants do not reveal vehicles that have never visited their sites.
        if (! $this->access->canReadWholeRegister($user, 'vehicles') || isset($filters['site_id'])) {
            $sessions = $this->sessionScope($user, 'vehicles', $filters)
                ->whereNotNull('c.vehicle_id')->select('c.vehicle_id');
            $query->whereIn('v.id', $sessions);
        }
        $this->vehicleFilters($query, $filters);

        return $query;
    }

    /** @param array<string, scalar|null> $filters */
    private function sessionScope(User $user, string $type, array $filters): Builder
    {
        $query = DB::table('charging_sessions as s')
            ->where('s.tenant_id', $this->tenant->get()->tenantId)
            ->whereIn('s.site_id', $this->access->siteIds($user, $type))
            ->leftJoin('charging_customer_sessions as c', fn (JoinClause $join) => $join
                ->on('c.tenant_id', 's.tenant_id')->on('c.session_id', 's.id'));
        if (isset($filters['site_id'])) {
            $query->where('s.site_id', $filters['site_id']);
        }

        return $query;
    }

    /** @param array<string, scalar|null> $filters */
    private function sessions(User $user, string $type, array $filters): Builder
    {
        $query = $this->sessionScope($user, $type, $filters)
            ->leftJoin('driver_vehicles as v', fn (JoinClause $join) => $join
                ->on('v.tenant_id', 'c.tenant_id')->on('v.id', 'c.vehicle_id'))
            ->leftJoin('users as driver', 'driver.public_id', 'c.subject_id')
            ->leftJoin('organizations as account', fn (JoinClause $join) => $join
                ->on('account.tenant_id', 'c.tenant_id')->on('account.id', 'c.charging_account_id'))
            ->join('sites as site', fn (JoinClause $join) => $join
                ->on('site.tenant_id', 's.tenant_id')->on('site.id', 's.site_id'));
        $this->owners($query);
        $this->vehicleFilters($query, array_diff_key($filters, ['vehicle_id' => true, 'account_type' => true]));
        if (isset($filters['vehicle_id'])) {
            $query->where('c.vehicle_id', $filters['vehicle_id']);
        }
        if (isset($filters['account_type'])) {
            $query->whereRaw("COALESCE(account.charging_account_type, 'personal') = ?", [$filters['account_type']]);
        }
        if (isset($filters['driver'])) {
            $this->contains($query, 'driver.name', (string) $filters['driver']);
        }
        if ($type === 'vehicle_sessions' && isset($filters['state'])) {
            $query->where('s.state', $filters['state']);
        }
        $this->dates($query, $filters, 's.requested_at', 'from', 'to');

        return $query;
    }

    private function owners(Builder $query): void
    {
        $query->leftJoin('driver_garages as g', fn (JoinClause $join) => $join
            ->on('g.tenant_id', 'v.tenant_id')->on('g.id', 'v.garage_id'))
            ->leftJoin('users as owner', 'owner.public_id', 'g.subject_id')
            ->leftJoin('organizations as o', fn (JoinClause $join) => $join
                ->on('o.tenant_id', 'g.tenant_id')->on('o.id', 'g.charging_account_id'));
    }

    /** @param array<string, scalar|null> $filters */
    private function vehicleFilters(Builder $query, array $filters): void
    {
        foreach (['manufacturer', 'model'] as $key) {
            if (isset($filters[$key])) {
                $query->whereRaw('UPPER(TRIM(v.'.$key.')) = ?', [$filters[$key]]);
            }
        }
        if (isset($filters['vehicle_id'])) {
            $query->where('v.id', $filters['vehicle_id']);
        }
        if (isset($filters['account_type'])) {
            $query->whereRaw("COALESCE(o.charging_account_type, 'personal') = ?", [$filters['account_type']]);
        }
        if (isset($filters['owner'])) {
            $this->contains($query, "COALESCE(o.name, owner.name, '')", (string) $filters['owner']);
        }
        $this->dates($query, $filters, 'v.created_at', 'registered_from', 'registered_to');
    }

    /** @param literal-string $column */
    private function contains(Builder $query, string $column, string $value): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtoupper(trim($value)));
        $query->whereRaw("UPPER({$column}) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
    }

    /** @param array<string, scalar|null> $filters */
    private function dates(Builder $query, array $filters, string $column, string $from, string $to): void
    {
        if (isset($filters[$from])) {
            $query->where($column, '>=', $filters[$from]);
        }
        if (isset($filters[$to])) {
            $query->where($column, '<', $filters[$to]);
        }
    }

    /**
     * @param  list<string>  $headers
     * @return Generator<int, list<scalar|null>>
     */
    private function rows(Builder $query, array $headers): Generator
    {
        foreach ($query->lazy(500) as $record) {
            $values = (array) $record;
            if (array_key_exists('energy_wh', $values)) {
                $values['energy_kwh'] = number_format(((int) $values['energy_wh']) / 1000, 3, '.', '');
            }
            yield array_map(fn (string $key): ?string => $values[$key] === null ? null : (string) $values[$key], $headers);
        }
    }
}
