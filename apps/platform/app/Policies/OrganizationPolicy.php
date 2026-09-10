<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class OrganizationPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->allows($user, PermissionKey::OrganizationView);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->sameTenant($organization)
            && $this->authorization->allows($user, PermissionKey::OrganizationView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->allows($user, PermissionKey::OrganizationManage);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->sameTenant($organization)
            && $this->authorization->allows($user, PermissionKey::OrganizationManage);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function restore(User $user, Organization $organization): bool
    {
        return false;
    }

    public function forceDelete(User $user, Organization $organization): bool
    {
        return false;
    }

    private function sameTenant(Organization $organization): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, (string) $organization->tenant_id);
    }
}
