<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class PurchaseRequestPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::ProcurementView);
    }

    public function view(User $user, PurchaseRequest $request): bool
    {
        return $this->sameTenant($request)
            && $this->authorization->allows($user, PermissionKey::ProcurementView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->allows($user, PermissionKey::ProcurementManage);
    }

    public function update(User $user, PurchaseRequest $request): bool
    {
        return $this->sameTenant($request)
            && $this->authorization->allows($user, PermissionKey::ProcurementManage);
    }

    public function approve(User $user, PurchaseRequest $request): bool
    {
        return $this->sameTenant($request)
            && $this->authorization->allows($user, PermissionKey::ProcurementApprove);
    }

    private function sameTenant(PurchaseRequest $request): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $request->tenant_id);
    }
}
