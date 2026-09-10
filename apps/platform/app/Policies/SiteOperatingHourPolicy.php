<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class SiteOperatingHourPolicy
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::LocationView);
    }

    public function view(User $user, SiteOperatingHour $hour): bool
    {
        return $this->allows($user, $hour, PermissionKey::LocationView);
    }

    public function update(User $user, SiteOperatingHour $hour): bool
    {
        return $this->allows($user, $hour, PermissionKey::LocationManage);
    }

    private function allows(User $user, SiteOperatingHour $hour, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null
            && hash_equals($context->tenantId, $hour->tenant_id)
            && $this->authorization->allows(
                $user,
                $permission,
                new ResourceScope(ScopeType::Site, (string) $hour->site_id),
            );
    }
}
