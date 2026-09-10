<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Modules\Identity\Domain\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof AuthSession) {
            return [
                'id' => $this->resource->getKey(),
                'name' => $this->resource->device_name,
                'type' => 'browser_session',
                'ip_address' => $this->resource->ip_address,
                'last_used_at' => $this->resource->last_active_at->utc()->toIso8601String(),
                'expires_at' => null,
                'current' => false,
            ];
        }

        return [
            'id' => $this->resource->device_id,
            'name' => $this->resource->name,
            'type' => 'mobile_token',
            'last_used_at' => $this->resource->last_used_at?->utc()->toIso8601String(),
            'expires_at' => $this->resource->expires_at->utc()->toIso8601String(),
            'current' => $request->user()?->currentAccessToken()?->getKey() === $this->resource->getKey(),
        ];
    }
}
