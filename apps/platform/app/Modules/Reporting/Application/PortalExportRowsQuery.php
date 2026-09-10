<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class PortalExportRowsQuery
{
    public function __construct(
        private AccessibleSitesQuery $sites,
        private AccessibleChargingSessionsQuery $sessions,
    ) {}

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array{headers:list<string>,rows:Generator<int, list<scalar|null>>}
     */
    public function for(string $type, User $user, array $filters = []): array
    {
        return match ($type) {
            'sessions' => [
                'headers' => [
                    'session_id', 'site_id', 'station_id', 'evse_id', 'connector_id', 'state',
                    'requested_at_utc', 'started_at_utc', 'stopped_at_utc', 'energy_wh',
                    'duration_seconds', 'currency', 'estimated_cost_minor', 'final_cost_minor',
                    'failure_reason',
                ],
                'rows' => $this->sessionRows($user, $filters),
            ],
            'assets' => [
                'headers' => [
                    'site_id', 'code', 'name', 'lifecycle_status', 'timezone', 'is_public',
                    'address', 'latitude', 'longitude', 'charger_count',
                ],
                'rows' => $this->assetRows($user),
            ],
            'finance' => [
                'headers' => [
                    'payment_intent_id', 'session_id', 'state', 'currency', 'requested_minor',
                    'authorized_minor', 'captured_minor', 'refunded_minor', 'created_at_utc',
                    'authorized_at_utc', 'captured_at_utc',
                ],
                'rows' => $this->financeRows($user, $filters),
            ],
            default => throw new \InvalidArgumentException('Unsupported portal export type.'),
        };
    }

    /** @param array<string, scalar|null> $filters @return Generator<int, list<scalar|null>> */
    private function sessionRows(User $user, array $filters): Generator
    {
        $accessibleIds = $this->sessions->for($user)->select('id');
        $query = DB::table('charging_sessions')
            ->whereIn('id', $accessibleIds)
            ->orderBy('id')
            ->select([
                'id', 'site_id', 'charging_station_id', 'evse_id', 'connector_id', 'state',
                'requested_at', 'started_at', 'stopped_at', 'energy_wh', 'duration_seconds',
                'currency', 'estimated_cost_minor', 'final_cost_minor', 'failure_reason',
            ]);
        $this->applyDateRange($query, $filters, 'requested_at');

        foreach ($query->lazyById() as $row) {
            yield [
                (string) $row->id, (string) $row->site_id, (string) $row->charging_station_id,
                (string) $row->evse_id, (string) $row->connector_id, (string) $row->state,
                $this->nullableString($row->requested_at), $this->nullableString($row->started_at),
                $this->nullableString($row->stopped_at), (int) $row->energy_wh,
                (int) $row->duration_seconds, $this->nullableString($row->currency),
                $this->nullableInt($row->estimated_cost_minor), $this->nullableInt($row->final_cost_minor),
                $this->nullableString($row->failure_reason),
            ];
        }
    }

    /** @return Generator<int, list<scalar|null>> */
    private function assetRows(User $user): Generator
    {
        $accessibleIds = $this->sites->for($user)->select('id');
        $query = DB::table('sites')
            ->whereIn('sites.id', $accessibleIds)
            ->leftJoin('charging_stations', 'charging_stations.site_id', '=', 'sites.id')
            ->groupBy([
                'sites.id', 'sites.code', 'sites.name', 'sites.lifecycle_status', 'sites.timezone',
                'sites.is_public', 'sites.address_line_1', 'sites.latitude', 'sites.longitude',
            ])
            ->orderBy('sites.id')
            ->selectRaw(
                'sites.id, sites.code, sites.name, sites.lifecycle_status, sites.timezone, '.
                'sites.is_public, sites.address_line_1, sites.latitude, sites.longitude, '.
                'COUNT(DISTINCT charging_stations.id) AS charger_count',
            );

        foreach ($query->lazyById(column: 'sites.id', alias: 'id') as $row) {
            yield [
                (string) $row->id, (string) $row->code, (string) $row->name,
                (string) $row->lifecycle_status, (string) $row->timezone, (bool) $row->is_public,
                $this->nullableString($row->address_line_1), $this->nullableString($row->latitude),
                $this->nullableString($row->longitude), (int) $row->charger_count,
            ];
        }
    }

    /** @param array<string, scalar|null> $filters @return Generator<int, list<scalar|null>> */
    private function financeRows(User $user, array $filters): Generator
    {
        $sessionIds = $this->sessions->for($user)->select('id');
        $query = DB::table('payment_intents')
            ->where('billable_type', ChargingSession::class)
            ->whereIn('billable_id', $sessionIds)
            ->orderBy('id')
            ->select([
                'id', 'billable_id', 'state', 'currency', 'amount_requested_minor',
                'amount_authorized_minor', 'amount_captured_minor', 'amount_refunded_minor',
                'created_at', 'authorized_at', 'captured_at',
            ]);
        $this->applyDateRange($query, $filters, 'created_at');

        foreach ($query->lazyById() as $row) {
            yield [
                (string) $row->id, (string) $row->billable_id, (string) $row->state,
                (string) $row->currency, (int) $row->amount_requested_minor,
                (int) $row->amount_authorized_minor, (int) $row->amount_captured_minor,
                (int) $row->amount_refunded_minor, $this->nullableString($row->created_at),
                $this->nullableString($row->authorized_at), $this->nullableString($row->captured_at),
            ];
        }
    }

    /** @param array<string, scalar|null> $filters */
    private function applyDateRange(Builder $query, array $filters, string $column): void
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if (is_string($from) && $from !== '') {
            $query->where($column, '>=', $from);
        }
        if (is_string($to) && $to !== '') {
            $query->where($column, '<', $to);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
