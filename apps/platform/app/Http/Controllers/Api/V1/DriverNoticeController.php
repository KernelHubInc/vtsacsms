<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Application\DriverNoticeFeed;
use App\Modules\CMS\Domain\Models\DriverNotice;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\JsonResponse;

final class DriverNoticeController extends Controller
{
    public function __invoke(CurrentTenant $tenant, DriverNoticeFeed $feed): JsonResponse
    {
        $notices = array_map(
            static fn (DriverNotice $notice): array => [
                'id' => $notice->id,
                'category' => $notice->category,
                'title' => $notice->title,
                'message' => $notice->message,
                'show_on_open' => $notice->show_on_open,
                'starts_at' => $notice->starts_at->toIso8601String(),
                'ends_at' => $notice->ends_at->toIso8601String(),
            ],
            $feed->forTenant($tenant->get()->tenantId)->all(),
        );

        return response()->json(['data' => $notices])
            ->header('Cache-Control', 'private, no-store');
    }
}
