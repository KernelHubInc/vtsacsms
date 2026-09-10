<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'email' => $this->resource->email,
            'organization_id' => $this->resource->organization_id,
            'role_id' => $this->resource->role_id,
            'scope' => [
                'type' => $this->resource->scope_type->value,
                'id' => $this->resource->scope_id,
            ],
            'expires_at' => $this->resource->expires_at->utc()->toIso8601String(),
            'accepted_at' => $this->resource->accepted_at?->utc()->toIso8601String(),
            'revoked_at' => $this->resource->revoked_at?->utc()->toIso8601String(),
        ];
    }
}
