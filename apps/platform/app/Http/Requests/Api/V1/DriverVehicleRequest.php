<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DriverVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('plate_number'))) {
            $this->merge(['plate_number' => strtoupper(trim($this->input('plate_number')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'max:80'],
            'plate_pending' => ['required', 'boolean'],
            'plate_number' => ['nullable', 'required_if:plate_pending,false', 'prohibited_if:plate_pending,true', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9 -]*$/'],
            'manufacturer' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:80'], 'variant' => ['nullable', 'string', 'max:80'],
            'connector_standards' => ['present', 'array', 'max:5'],
            'connector_standards.*' => ['string', 'distinct', Rule::in(['Type 2', 'CCS2', 'CHAdeMO', 'GB/T', 'NACS'])],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
