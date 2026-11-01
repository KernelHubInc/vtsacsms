<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Database\Eloquent\Builder;

final readonly class AccessibleStationsQuery
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @return Builder<ChargingStation> */
    public function for(User $user, PermissionKey $permission = PermissionKey::AssetView): Builder
    {
        $query = ChargingStation::query();
        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        return $query->whereIn('site_id', app(AccessibleSitesQuery::class)->for($user, $permission)->select('id'));
    }
}
