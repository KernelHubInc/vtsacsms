<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Models\User;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;

final readonly class MaintenanceAuthorization
{
    public function __construct(private AuthorizationService $authorization) {}

    public function allowsSite(User $user, PermissionKey $permission, string $siteId): bool
    {
        return $this->authorization->allows($user, $permission)
            || $this->authorization->allows(
                $user,
                $permission,
                new ResourceScope(ScopeType::Site, $siteId),
            );
    }

    public function allowsWorkOrder(User $user, PermissionKey $permission, WorkOrder $workOrder): bool
    {
        return $this->allowsSite($user, $permission, (string) $workOrder->site_id);
    }
}
