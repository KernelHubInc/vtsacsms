<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class VendorInvoicePolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::ProcurementView);
    }

    public function view(User $user, VendorInvoice $invoice): bool
    {
        return $this->sameTenant($invoice)
            && $this->authorization->allows($user, PermissionKey::ProcurementView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->allows($user, PermissionKey::ProcurementManage);
    }

    public function approve(User $user, VendorInvoice $invoice): bool
    {
        return $this->sameTenant($invoice)
            && $this->authorization->allows($user, PermissionKey::ProcurementApprove);
    }

    private function sameTenant(VendorInvoice $invoice): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $invoice->tenant_id);
    }
}
