<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreChargingStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'ulid'],
            'charger_model_id' => ['nullable', 'ulid', 'exists:charger_models,id'],
            'ocpp_version_id' => ['nullable', 'ulid', 'exists:ocpp_versions,id'],
            'ocpp_security_profile_id' => ['nullable', 'ulid', 'exists:ocpp_security_profiles,id'],
            'name' => ['required', 'string', 'max:160'],
            'charge_point_identity' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/', 'unique:charging_stations,charge_point_identity'],
            'serial_number' => ['required', 'string', 'max:160', Rule::unique('charging_stations', 'serial_number')->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)],
            'qr_identifier' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:charging_stations,qr_identifier'],
            'lifecycle_status' => ['sometimes', Rule::enum(AssetLifecycleStatus::class)],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
