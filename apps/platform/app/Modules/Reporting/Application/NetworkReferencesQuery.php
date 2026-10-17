<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NetworkReferencesQuery
{
    /**
     * Read-only deletion preflight, including cross-context references without foreign keys.
     *
     * @return list<string>
     */
    public function forRecord(string $tenantId, string $id): array
    {
        $references = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'audit_events') {
                continue;
            }
            $columns = Schema::getColumnListing($name);
            $keys = array_intersect($columns, [
                'site_id', 'charging_station_id', 'charger_id', 'evse_id',
                'connector_id', 'asset_id', 'scope_id', 'target_id',
            ]);
            if ($keys === [] || ! in_array('tenant_id', $columns, true)) {
                continue;
            }
            $query = DB::table($name)->where('tenant_id', $tenantId);
            $query->where(function ($query) use ($keys, $id): void {
                foreach ($keys as $key) {
                    $query->orWhere($key, $id);
                }
            });
            if ($query->exists()) {
                $references[] = str_replace('_', ' ', $name);
            }
        }

        return $references;
    }
}
