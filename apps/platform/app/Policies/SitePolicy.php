<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class SitePolicy
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->currentTenant->getOrNull() !== null
            && $this->authorization->holdsAnywhere($user, PermissionKey::LocationView);
    }

    public function view(User $user, Site $site): bool
    {
        $context = $this->currentTenant->getOrNull();

        return $context !== null
            && hash_equals($context->tenantId, $site->tenant_id)
            && $this->authorization->allows(
                $user,
                PermissionKey::LocationView,
                new ResourceScope(ScopeType::Site, (string) $site->getKey()),
            );
    }

    public function create(User $user): bool
    {
        return $this->currentTenant->getOrNull() !== null
            && $this->authorization->allows($user, PermissionKey::LocationManage);
    }

    public function update(User $user, Site $site): bool
    {
        $context = $this->currentTenant->getOrNull();

        return $context !== null
            && hash_equals($context->tenantId, $site->tenant_id)
            && $this->authorization->allows(
                $user,
                PermissionKey::LocationManage,
                new ResourceScope(ScopeType::Site, (string) $site->getKey()),
            );
    }

    public function delete(User $user, Site $site): bool
    {
        return false;
    }

    public function restore(User $user, Site $site): bool
    {
        return false;
    }

    public function forceDelete(User $user, Site $site): bool
    {
        return false;
    }
}
