<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Notifications\Domain\Models\NotificationDevice;
use Illuminate\Support\Facades\DB;

final class NotificationDeviceRegistry
{
    public function register(User $user, MobileAccessToken $authToken, string $installationId): void
    {
        $hash = hash('sha256', $installationId);

        DB::transaction(function () use ($user, $authToken, $installationId, $hash): void {
            // One Firebase installation belongs to only the most recently signed-in tenant.
            NotificationDevice::query()->where('installation_hash', $hash)
                ->where('auth_token_id', '!=', $authToken->getKey())->delete();

            NotificationDevice::query()->updateOrCreate(
                ['tenant_id' => $authToken->tenant_id, 'auth_token_id' => $authToken->getKey()],
                [
                    'user_id' => $user->getKey(),
                    'platform' => 'android',
                    'installation_hash' => $hash,
                    'installation_ciphertext' => $installationId,
                    'last_registered_at' => now('UTC'),
                ],
            );
        });
    }

    public function unregister(MobileAccessToken $authToken): void
    {
        NotificationDevice::query()
            ->where('tenant_id', $authToken->tenant_id)
            ->where('auth_token_id', $authToken->getKey())
            ->delete();
    }
}
