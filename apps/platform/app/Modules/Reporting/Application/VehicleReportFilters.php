<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Assets\Domain\VehicleText;
use App\Modules\Charging\Domain\ChargingSessionState;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class VehicleReportFilters
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return array<string, string>
     */
    public static function validate(array $filters, string $type): array
    {
        $rules = [
            'manufacturer' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'vehicle_id' => ['nullable', 'ulid'],
            'site_id' => ['nullable', 'ulid'],
            'owner' => ['nullable', 'string', 'max:100'],
            'driver' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::in(['personal', 'business', 'fleet'])],
            'state' => ['nullable', Rule::enum(ChargingSessionState::class)],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d'],
        ];
        if ($type === 'vehicles') {
            unset($rules['driver'], $rules['state']);
        } elseif (in_array($type, ['vehicle_usage', 'vehicle_models'], true)) {
            unset($rules['state']);
        }
        $filters = array_filter(array_intersect_key($filters, $rules), fn ($value): bool => $value !== null && $value !== '');
        foreach (['manufacturer', 'model'] as $field) {
            if (isset($filters[$field]) && is_string($filters[$field])) {
                $filters[$field] = VehicleText::normalize($filters[$field]);
            }
        }
        $validator = Validator::make($filters, $rules);
        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first());
        }
        if (isset($filters['registered_from'], $filters['registered_to']) && $filters['registered_from'] >= $filters['registered_to']) {
            throw new DomainException('Registration end date must be after its start date.');
        }

        /** @var array<string, string> $validated */
        $validated = $validator->validated();

        return $validated;
    }
}
