<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class InventoryItemPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $this->sameTenant($item)
            && $this->authorization->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public function create(User $user): bool
    {
        return $this->tenant->has() && $this->authorization->allows($user, PermissionKey::InventoryOperate);
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $this->sameTenant($item)
            && $this->authorization->allows($user, PermissionKey::InventoryOperate);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return false;
    }

    public function restore(User $user, InventoryItem $item): bool
    {
        return false;
    }

    public function forceDelete(User $user, InventoryItem $item): bool
    {
        return false;
    }

    private function sameTenant(InventoryItem $item): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, $item->tenant_id);
    }
}
