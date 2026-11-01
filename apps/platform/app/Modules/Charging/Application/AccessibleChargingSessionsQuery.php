<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Models\User;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Database\Eloquent\Builder;

final readonly class AccessibleChargingSessionsQuery
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {}

    /** @return Builder<ChargingSession> */
    public function for(User $user, PermissionKey $permission = PermissionKey::ChargingSessionView): Builder
    {
        $query = ChargingSession::query();
        if (! $user->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        return $query->whereIn('site_id', app(AccessibleSitesQuery::class)->for($user, $permission)->select('id'));
    }
}
