<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;

final readonly class InventoryAuthorization
{
    public function __construct(private AuthorizationService $authorization) {}

    public function allowsWarehouse(User $user, PermissionKey $permission, Warehouse $warehouse): bool
    {
        return $this->authorization->allows($user, $permission)
            || $this->authorization->allows(
                $user,
                $permission,
                new ResourceScope(ScopeType::Warehouse, (string) $warehouse->getKey()),
            )
            || ($warehouse->site_id !== null && $this->authorization->allows(
                $user,
                $permission,
                new ResourceScope(ScopeType::Site, (string) $warehouse->site_id),
            ));
    }
}
