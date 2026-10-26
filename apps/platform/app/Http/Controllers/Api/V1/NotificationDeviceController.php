<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterNotificationDeviceRequest;
use App\Models\User;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Notifications\Application\NotificationDeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationDeviceController extends Controller
{
    public function store(RegisterNotificationDeviceRequest $request, NotificationDeviceRegistry $registry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authToken = $user->currentAccessToken();
        abort_unless($authToken instanceof MobileAccessToken, 403);

        $registry->register($user, $authToken, (string) $request->validated('installation_id'));

        return response()->json([], 204);
    }

    public function destroy(Request $request, NotificationDeviceRegistry $registry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $authToken = $user->currentAccessToken();
        abort_unless($authToken instanceof MobileAccessToken, 403);

        $registry->unregister($authToken);

        return response()->json([], 204);
    }
}
