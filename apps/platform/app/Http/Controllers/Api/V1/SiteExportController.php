<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Locations\Application\TenantSiteExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SiteExportController extends Controller
{
    public function __invoke(Request $request, TenantSiteExport $export): Response
    {
        /** @var User $user */
        $user = $request->user();

        return response($export->csv($user), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="power-solutions-sites.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
