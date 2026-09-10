<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class OrganizationLifecycleService
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuditRecorder $audit,
    ) {}

    public function setActive(Organization $organization, bool $active, string $reason): Organization
    {
        $context = $this->tenant->get();
        if (! hash_equals($context->tenantId, (string) $organization->tenant_id)) {
            throw new DomainException('The organization is outside the active tenant.');
        }

        return DB::transaction(function () use ($active, $organization, $reason): Organization {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            if ((bool) $locked->is_active === $active) {
                return $locked;
            }

            $before = $locked->attributesToArray();
            $locked->forceFill(['is_active' => $active])->save();

            $this->audit->record(new AuditEntry(
                $active ? 'organizations.organization.reactivated' : 'organizations.organization.deactivated',
                'organization',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reason,
                before: $before,
                after: $locked->fresh()->attributesToArray(),
            ));

            return $locked;
        });
    }
}
