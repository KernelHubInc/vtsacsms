<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class TariffPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PermissionKey::TariffView);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, PermissionKey::TariffManage);
    }

    public function view(User $user, Tariff $tariff): bool
    {
        return $this->owns($tariff) && $this->authorization->allows($user, PermissionKey::TariffView);
    }

    public function update(User $user, Tariff $tariff): bool
    {
        return $this->owns($tariff) && $this->authorization->allows($user, PermissionKey::TariffManage);
    }

    public function publish(User $user, Tariff $tariff): bool
    {
        return $this->owns($tariff) && $this->authorization->allows($user, PermissionKey::TariffPublish);
    }

    private function owns(Tariff $tariff): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, (string) $tariff->tenant_id);
    }
}
