<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Maintenance\Application\MaintenanceAuthorization;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class WorkOrderPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
        private MaintenanceAuthorization $maintenance,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::MaintenanceView);
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $this->sameTenant($workOrder)
            && $this->maintenance->allowsWorkOrder($user, PermissionKey::MaintenanceView, $workOrder);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::MaintenanceDispatch);
    }

    public function dispatch(User $user, WorkOrder $workOrder): bool
    {
        return $this->sameTenant($workOrder)
            && $this->maintenance->allowsWorkOrder($user, PermissionKey::MaintenanceDispatch, $workOrder);
    }

    public function perform(User $user, WorkOrder $workOrder): bool
    {
        return $this->sameTenant($workOrder)
            && $this->maintenance->allowsWorkOrder($user, PermissionKey::MaintenancePerform, $workOrder);
    }

    public function verify(User $user, WorkOrder $workOrder): bool
    {
        return $this->sameTenant($workOrder)
            && $this->maintenance->allowsWorkOrder($user, PermissionKey::MaintenanceVerify, $workOrder);
    }

    private function sameTenant(WorkOrder $workOrder): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $workOrder->tenant_id);
    }
}
