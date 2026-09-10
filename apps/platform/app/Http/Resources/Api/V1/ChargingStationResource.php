<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChargingStationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'site_id' => $this->resource->site_id, 'name' => $this->resource->name, 'charge_point_identity' => $this->resource->charge_point_identity, 'serial_number' => $this->resource->serial_number, 'qr_identifier' => $this->resource->qr_identifier, 'lifecycle_status' => $this->resource->lifecycle_status->value, 'is_public' => (bool) $this->resource->is_public, 'charger_model_id' => $this->resource->charger_model_id, 'ocpp_version_id' => $this->resource->ocpp_version_id, 'ocpp_security_profile_id' => $this->resource->ocpp_security_profile_id];
    }
}
