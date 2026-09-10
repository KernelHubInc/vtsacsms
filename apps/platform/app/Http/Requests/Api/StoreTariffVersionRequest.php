<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Tariffs\Domain\DiscountKind;
use App\Modules\Tariffs\Domain\TariffDimension;
use App\Modules\Tariffs\Domain\TaxTreatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTariffVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'tax_treatment' => ['required', Rule::enum(TaxTreatment::class)],
            'tax_rate_basis_points' => ['nullable', 'integer', 'between:0,10000'],
            'minimum_fee_minor' => ['nullable', 'integer', 'min:0'],
            'maximum_fee_minor' => ['nullable', 'integer', 'gte:minimum_fee_minor'],
            'operator_id' => ['nullable', 'ulid'],
            'site_id' => ['nullable', 'ulid'],
            'connector_id' => ['nullable', 'ulid'],
            'timezone' => ['required', 'timezone:all'],
            'components' => ['required', 'array', 'min:1', 'max:100'],
            'components.*.dimension' => ['required', Rule::enum(TariffDimension::class)],
            'components.*.price_minor' => ['required', 'integer', 'min:0'],
            'components.*.unit_quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.day_of_week_mask' => ['sometimes', 'integer', 'between:1,127'],
            'components.*.starts_at_local' => ['nullable', 'date_format:H:i:s', 'required_with:components.*.ends_at_local'],
            'components.*.ends_at_local' => ['nullable', 'date_format:H:i:s', 'required_with:components.*.starts_at_local'],
            'components.*.priority' => ['sometimes', 'integer', 'between:0,65535'],
            'discounts' => ['sometimes', 'array', 'max:50'],
            'discounts.*.name' => ['required', 'string', 'max:160'],
            'discounts.*.code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'discounts.*.kind' => ['required', Rule::enum(DiscountKind::class)],
            'discounts.*.value' => ['required', 'integer', 'min:0'],
            'discounts.*.is_automatic' => ['sometimes', 'boolean'],
            'discounts.*.effective_from' => ['nullable', 'date'],
            'discounts.*.effective_to' => ['nullable', 'date', 'after:discounts.*.effective_from'],
        ];
    }
}
