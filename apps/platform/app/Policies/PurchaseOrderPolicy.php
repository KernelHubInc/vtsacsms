<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class PurchaseOrderPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::ProcurementView);
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->sameTenant($order)
            && $this->authorization->allows($user, PermissionKey::ProcurementView);
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $this->sameTenant($order)
            && $this->authorization->allows($user, PermissionKey::ProcurementManage);
    }

    public function approve(User $user, PurchaseOrder $order): bool
    {
        return $this->sameTenant($order)
            && $this->authorization->allows($user, PermissionKey::ProcurementApprove);
    }

    private function sameTenant(PurchaseOrder $order): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $order->tenant_id);
    }
}
