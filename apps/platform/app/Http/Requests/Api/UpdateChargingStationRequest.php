<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateChargingStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'charger_model_id' => ['sometimes', 'nullable', 'ulid', 'exists:charger_models,id'],
            'ocpp_version_id' => ['sometimes', 'nullable', 'ulid', 'exists:ocpp_versions,id'],
            'ocpp_security_profile_id' => ['sometimes', 'nullable', 'ulid', 'exists:ocpp_security_profiles,id'],
            'lifecycle_status' => ['sometimes', Rule::enum(AssetLifecycleStatus::class)],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
