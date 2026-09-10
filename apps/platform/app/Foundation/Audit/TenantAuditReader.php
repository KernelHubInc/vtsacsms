<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use App\Foundation\Audit\Models\AuditEvent;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class TenantAuditReader
{
    public function __construct(private CurrentTenant $currentTenant) {}

    /**
     * @return Collection<int, AuditEvent>
     */
    public function recent(int $limit = 100): Collection
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Audit query limit must be between 1 and 500.');
        }

        return AuditEvent::query()
            ->where('tenant_id', $this->currentTenant->get()->tenantId)
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
