<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Read-only maintenance inventory. A match is evidence for review, never permission to delete. */
final class DemoDataAudit
{
    private const FIELDS = [
        'name', 'title', 'code', 'slug', 'key', 'sku', 'email', 'provider', 'environment',
        'charge_point_identity', 'qr_identifier', 'uid', 'serial_number', 'lot_number',
        'request_number', 'po_number', 'invoice_reference', 'document_reference',
        'receipt_number', 'plan_number', 'adjustment_number', 'incident_number',
        'work_order_number', 'ticket_number', 'legal_name', 'reason_code',
        'address_line_1', 'description', 'summary', 'hero_heading',
    ];

    private const HISTORY = [
        'audit_events', 'audit_chain_heads', 'integration_outbox', 'integration_inbox',
        'jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks',
        'migrations', 'password_reset_tokens', 'personal_access_tokens',
    ];

    /** @return array<string, mixed> */
    public function inspect(string $tenantId, int $sampleLimit = 100): array
    {
        if (! DB::table('tenants')->where('id', $tenantId)->exists()) {
            throw new InvalidArgumentException('The tenant does not exist.');
        }
        if ($sampleLimit < 1 || $sampleLimit > 500) {
            throw new InvalidArgumentException('Sample limit must be between 1 and 500.');
        }

        $tables = [];
        foreach (Schema::getTables() as $definition) {
            $table = $definition['name'];
            if (isset($definition['schema']) && ! in_array($definition['schema'], ['public', 'main'], true)) {
                continue;
            }
            $definitions = Schema::getColumns($table);
            $columns = array_column($definitions, 'name');
            $textColumns = array_column(array_filter($definitions, static fn (array $column): bool => in_array($column['type_name'], ['varchar', 'char', 'bpchar', 'text', 'nvarchar', 'character varying'], true)), 'name');
            $fields = array_values(array_intersect(self::FIELDS, $textColumns));
            $query = DB::table($table);
            $scope = 'shared_or_indirect';
            if (in_array('tenant_id', $columns, true)) {
                $query->where('tenant_id', $tenantId);
                $scope = 'tenant';
            } elseif ($table === 'tenants') {
                $query->where('id', $tenantId);
                $scope = 'tenant';
            } elseif ($table === 'users') {
                $query->whereIn('id', DB::table('memberships')->select('user_id')->where('tenant_id', $tenantId));
                $scope = 'tenant_members';
            }
            $entry = ['scope' => $scope, 'rows' => (clone $query)->count(), 'matched_rows' => 0, 'samples' => []];
            if (in_array($table, self::HISTORY, true) || $fields === [] || ! in_array('id', $columns, true)) {
                $entry['review'] = 'count_only';
                $tables[$table] = $entry;

                continue;
            }
            $query->where(function (Builder $query) use ($fields): void {
                foreach ($fields as $field) {
                    foreach (['%demo%', '%example.%', '%.test%', '%.local%', '%local development%', '%placeholder%', 'fake', '%test data%'] as $pattern) {
                        $query->orWhereLike($field, $pattern);
                    }
                }
            });
            $query->select(array_unique(['id', ...$fields]))->orderBy('id')->chunk(500, function ($rows) use (&$entry, $fields, $sampleLimit): void {
                foreach ($rows as $row) {
                    $matched = [];
                    foreach ($fields as $field) {
                        if ($this->matches((string) ($row->{$field} ?? ''))) {
                            $matched[] = $field;
                        }
                    }
                    if ($matched === []) {
                        continue;
                    }
                    $entry['matched_rows']++;
                    if (count($entry['samples']) < $sampleLimit) {
                        // Report identifiers and field names, never email addresses or financial/customer payloads.
                        $entry['samples'][] = ['id' => (string) $row->id, 'matched_fields' => $matched];
                    }
                }
            });
            $entry['truncated'] = $entry['matched_rows'] > count($entry['samples']);
            $tables[$table] = $entry;
        }
        ksort($tables);

        return [
            'environment' => app()->environment(),
            'tenant_id' => $tenantId,
            'generated_at' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'limitations' => [
                'Text matches require provenance and dependency review; renamed samples may not match.',
                'Shared or indirectly scoped rows require separate ownership review.',
                'History is counted and retained; this command does not inspect secret or raw payload fields.',
                'A zero match count does not prove the absence of sample data.',
            ],
            'tables' => $tables,
        ];
    }

    public function matches(string $value): bool
    {
        return preg_match('/(?:\bdemo\b|demo[_-]|@demo\.|@[^\s]+\.(?:test|local)\b|\bexample\.(?:test|com|org)\b|\blocal development\b|\bplaceholder\b|\btest data\b|^fake$)/iu', $value) === 1;
    }
}
