<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class SupportTicketPolicy
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->has()
            && $this->authorization->holdsAnywhere($user, PermissionKey::SupportView);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->sameTenant($ticket)
            && $this->authorization->holdsAnywhere($user, PermissionKey::SupportView);
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $this->sameTenant($ticket)
            && $this->authorization->holdsAnywhere($user, PermissionKey::SupportManage);
    }

    private function sameTenant(SupportTicket $ticket): bool
    {
        $context = $this->tenant->getOrNull();

        return $context !== null && hash_equals($context->tenantId, (string) $ticket->tenant_id);
    }
}
