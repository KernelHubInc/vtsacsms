<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\Models\AuthorizationToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AuthorizationTokenService
{
    public function issue(CarbonImmutable $expiresAt): GeneratedAuthorizationToken
    {
        $plainText = 'vtsa_'.Str::random(40);
        $token = AuthorizationToken::query()->create([
            'token_hash' => hash('sha256', $plainText),
            'token_hint' => substr($plainText, -8),
            'status' => 'active',
            'expires_at' => $expiresAt,
        ]);

        return new GeneratedAuthorizationToken($token, $plainText);
    }

    /** @return array{status: string, expires_at: string|null, parent_token: null} */
    public function authorize(string $plainText, string $chargingStationId): array
    {
        $token = AuthorizationToken::query()
            ->where('token_hash', hash('sha256', $plainText))
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now('UTC'))
            ->lockForUpdate()
            ->first();

        if ($token === null || $token->session_id === null) {
            return ['status' => 'Invalid', 'expires_at' => null, 'parent_token' => null];
        }

        $belongsToStation = $token->newQuery()
            ->whereKey($token->getKey())
            ->whereHas(
                'session',
                fn ($query) => $query->where('charging_station_id', $chargingStationId),
            )->exists();
        if (! $belongsToStation) {
            return ['status' => 'Invalid', 'expires_at' => null, 'parent_token' => null];
        }

        $token->forceFill(['used_at' => $token->used_at ?? now('UTC')])->save();

        return [
            'status' => 'Accepted',
            'expires_at' => $token->expires_at->utc()->toISOString(),
            'parent_token' => null,
        ];
    }
}
