<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class ChargingSessionPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->holdsAnywhere($user, PermissionKey::ChargingSessionView);
    }

    public function view(User $user, ChargingSession $session): bool
    {
        return $this->allows($user, $session, PermissionKey::ChargingSessionView);
    }

    public function remoteCommand(User $user, ChargingSession $session): bool
    {
        return $this->allows($user, $session, PermissionKey::ChargingRemoteCommand);
    }

    public function review(User $user, ChargingSession $session): bool
    {
        return $this->allows($user, $session, PermissionKey::ChargingSessionReview);
    }

    private function allows(User $user, ChargingSession $session, PermissionKey $permission): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null
            && hash_equals($context->tenantId, (string) $session->tenant_id)
            && $this->authorization->allows($user, $permission, new ResourceScope(ScopeType::Site, (string) $session->site_id));
    }
}
