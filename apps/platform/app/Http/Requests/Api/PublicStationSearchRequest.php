<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class PublicStationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'limit' => $this->input('limit', 100),
            'radius_m' => $this->input('radius_m', 10000),
            'open_now' => $this->has('open_now') ? $this->boolean('open_now') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'radius_m' => ['required_with:latitude', 'integer', 'min:100', 'max:100000'],
            'west' => ['nullable', 'numeric', 'between:-180,180', 'required_with:south,east,north'],
            'south' => ['nullable', 'numeric', 'between:-90,90', 'required_with:west,east,north'],
            'east' => ['nullable', 'numeric', 'between:-180,180', 'required_with:west,south,north', 'gt:west'],
            'north' => ['nullable', 'numeric', 'between:-90,90', 'required_with:west,south,east', 'gt:south'],
            'connector' => ['nullable', 'string', 'max:40'],
            'current' => ['nullable', 'in:AC,DC'],
            'query' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'amenity' => ['nullable', 'string', 'max:60'],
            'min_power_w' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'availability' => ['nullable', 'in:available,busy,faulted,offline,stale,unknown'],
            'operator_id' => ['nullable', 'ulid'],
            'site_type' => ['nullable', 'in:public_parking,retail,workplace,fleet,highway,hospitality'],
            'open_now' => ['nullable', 'boolean'],
            'limit' => ['required', 'integer', 'min:1', 'max:250'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $values = $this->validated();
        $filters = ['limit' => (int) $values['limit']];
        if (isset($values['latitude'], $values['longitude'])) {
            $filters['latitude'] = (float) $values['latitude'];
            $filters['longitude'] = (float) $values['longitude'];
            $filters['radius_m'] = (int) $values['radius_m'];
        }
        if (isset($values['west'], $values['south'], $values['east'], $values['north'])) {
            $filters['west'] = (float) $values['west'];
            $filters['south'] = (float) $values['south'];
            $filters['east'] = (float) $values['east'];
            $filters['north'] = (float) $values['north'];
        }
        foreach (['connector', 'current', 'query', 'city', 'amenity', 'availability', 'operator_id', 'site_type'] as $filter) {
            if (isset($values[$filter]) && $values[$filter] !== '') {
                $filters[$filter] = $values[$filter];
            }
        }
        if (isset($values['min_power_w'])) {
            $filters['min_power_w'] = (int) $values['min_power_w'];
        }
        if (($values['open_now'] ?? null) === true) {
            $filters['open_now'] = true;
        }

        return $filters;
    }
}
