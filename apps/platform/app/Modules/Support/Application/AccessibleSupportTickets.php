<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;

final readonly class AccessibleSupportTickets
{
    /** @return Builder<SupportTicket> */
    public function for(User $user, PermissionKey $permission = PermissionKey::SupportView): Builder
    {
        $query = SupportTicket::query();
        if (app(AuthorizationService::class)->allows($user, $permission)) {
            return $query;
        }

        return $query->where('category', '<>', SupportTicket::ACCOUNT_DELETION)
            ->whereIn('site_id', app(AccessibleSitesQuery::class)->for($user, $permission, false)->select('id'));
    }
}
