<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ChargingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'store' => ['name' => ['required', 'string', 'max:160'], 'type' => ['required', 'in:business,fleet']],
            'invite' => ['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'in:admin,billing_manager,fleet_manager,driver']],
            'member' => ['role' => ['present', 'nullable', 'in:admin,billing_manager,fleet_manager,driver']],
            'assign' => ['subject_id' => ['required', 'ulid'], 'assigned' => ['required', 'boolean']],
            'limit' => ['target_type' => ['required', 'in:driver,vehicle'], 'target_id' => ['required', 'ulid'],
                'amount_minor' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000']],
            default => [],
        };
    }
}
