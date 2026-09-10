<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SiteResource;
use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SiteController extends Controller
{
    public function index(Request $request, AccessibleSitesQuery $sites): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return SiteResource::collection($sites->for($user)->cursorPaginate(50));
    }

    public function show(Request $request, string $site, AccessibleSitesQuery $sites): SiteResource
    {
        /** @var User $user */
        $user = $request->user();

        return new SiteResource($sites->for($user)->whereKey($site)->firstOrFail());
    }
}
