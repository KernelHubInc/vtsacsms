<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Foundation\Audit\AuditLogQuery;
use App\Models\User;
use Generator;

final readonly class AuditReportQuery
{
    /** @param array<string, scalar|null> $filters
     * @return array{headers:list<string>,rows:Generator<int,list<scalar|null>>}
     */
    public function for(User $user, array $filters): array
    {
        app(PortalReportAccess::class)->assertCanExport($user, 'audit');

        return ['headers' => ['event_id', 'occurred_at_utc', 'actor_type', 'actor_id', 'action',
            'target_type', 'target_id', 'result', 'correlation_id', 'details'],
            'rows' => $this->rows($user, $filters)];
    }

    /** @param array<string, scalar|null> $filters @return Generator<int,list<scalar|null>> */
    private function rows(User $user, array $filters): Generator
    {
        $query = app(AuditLogQuery::class)->for($user, $filters);
        if (isset($filters['_before'])) {
            $query->where('occurred_at', '<', $filters['_before']);
        }
        foreach ($query->lazyById() as $event) {
            yield [(string) $event->getKey(), $event->occurred_at->utc()->format('Y-m-d H:i:s'),
                $event->actor_type->value, $event->actor_id, $event->action, $event->target_type,
                $event->target_id, $event->result->value, $event->correlation_id, AuditLogQuery::details($event)];
        }
    }
}
