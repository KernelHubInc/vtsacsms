<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class EvsePolicy
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::AssetView);
    }

    public function view(User $user, Evse $evse): bool
    {
        return $this->allows($user, $evse, PermissionKey::AssetView);
    }

    public function update(User $user, Evse $evse): bool
    {
        return $this->allows($user, $evse, PermissionKey::AssetManage);
    }

    private function allows(User $user, Evse $evse, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();
        $siteId = $evse->station()->value('site_id');

        return $context !== null
            && hash_equals($context->tenantId, $evse->tenant_id)
            && is_string($siteId)
            && $this->authorization->allows($user, $permission, new ResourceScope(ScopeType::Site, $siteId));
    }
}
