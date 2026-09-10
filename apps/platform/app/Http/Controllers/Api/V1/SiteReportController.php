<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Locations\Application\TenantSiteReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteReportController extends Controller
{
    public function __invoke(Request $request, TenantSiteReport $report): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $report->generate($user)]);
    }
}
