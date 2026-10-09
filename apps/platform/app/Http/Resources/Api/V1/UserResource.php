<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'first_name' => $this->resource->first_name,
            'middle_name' => $this->resource->middle_name,
            'last_name' => $this->resource->last_name,
            'birth_date' => $this->resource->birth_date,
            'email' => $this->resource->email,
            'email_verified' => $this->resource->hasVerifiedEmail(),
            'mobile_verified' => $this->resource->mobile_verified_at !== null,
            'mfa_required' => (bool) $this->resource->mfa_required,
            'active' => $this->resource->isEnabled(),
        ];
    }
}
