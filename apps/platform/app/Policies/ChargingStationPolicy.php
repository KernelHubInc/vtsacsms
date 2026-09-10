<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class ChargingStationPolicy
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->getOrNull() !== null && $this->authorization->holdsAnywhere($user, PermissionKey::AssetView);
    }

    public function view(User $user, ChargingStation $station): bool
    {
        return $this->allows($user, $station, PermissionKey::AssetView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->getOrNull() !== null
            && $this->authorization->allows($user, PermissionKey::AssetManage);
    }

    public function update(User $user, ChargingStation $station): bool
    {
        return $this->allows($user, $station, PermissionKey::AssetManage);
    }

    public function delete(User $user, ChargingStation $station): bool
    {
        return false;
    }

    public function restore(User $user, ChargingStation $station): bool
    {
        return false;
    }

    public function forceDelete(User $user, ChargingStation $station): bool
    {
        return false;
    }

    private function allows(User $user, ChargingStation $station, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $station->tenant_id) && $this->authorization->allows($user, $permission, new ResourceScope(ScopeType::Site, (string) $station->site_id));
    }
}
