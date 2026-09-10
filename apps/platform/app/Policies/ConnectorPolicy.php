<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class ConnectorPolicy
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::AssetView);
    }

    public function view(User $user, Connector $connector): bool
    {
        return $this->allows($user, $connector, PermissionKey::AssetView);
    }

    public function update(User $user, Connector $connector): bool
    {
        return $this->allows($user, $connector, PermissionKey::AssetManage);
    }

    private function allows(User $user, Connector $connector, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();
        $siteId = $connector->evse()->join(
            'charging_stations',
            'charging_stations.id',
            '=',
            'evses.charging_station_id',
        )->value('charging_stations.site_id');

        return $context !== null
            && hash_equals($context->tenantId, $connector->tenant_id)
            && is_string($siteId)
            && $this->authorization->allows($user, $permission, new ResourceScope(ScopeType::Site, $siteId));
    }
}
