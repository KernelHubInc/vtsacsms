<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveSessionReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['complete', 'estimated', 'unbillable'])],
            'notes' => ['required', 'string', 'max:2000'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.energy_wh' => ['sometimes', 'integer', 'min:0'],
            'adjustments.duration_seconds' => ['sometimes', 'integer', 'min:0'],
            'adjustments.parking_seconds' => ['sometimes', 'integer', 'min:0'],
            'adjustments.idle_seconds' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
