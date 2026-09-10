<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Maintenance\Domain\WorkOrderState;
use Illuminate\Validation\Rule;

final class MaintenanceTransitionRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_state' => ['required', Rule::enum(WorkOrderState::class)],
            'reason_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function target(): WorkOrderState
    {
        return WorkOrderState::from((string) $this->validated('target_state'));
    }
}
