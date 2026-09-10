<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

final class MaintenanceChecklistRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(['pass', 'fail', 'complete', 'not_applicable'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
