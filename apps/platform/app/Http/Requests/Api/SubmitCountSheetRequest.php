<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use LogicException;

final class SubmitCountSheetRequest extends TenantFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'observations' => ['required', 'array', 'min:1', 'max:1000'],
            'observations.*.line_id' => ['required', 'ulid', 'distinct'],
            'observations.*.counted_quantity_base' => ['required', 'integer', 'min:0'],
            'recount_threshold_base' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, int> */
    public function observations(): array
    {
        $rawObservations = $this->validated('observations');
        if (! is_array($rawObservations)) {
            throw new LogicException('Validated count observations are unavailable.');
        }
        $observations = [];
        foreach ($rawObservations as $rawObservation) {
            if (! is_array($rawObservation)) {
                throw new LogicException('A validated count observation is invalid.');
            }
            $observations[(string) $rawObservation['line_id']] = (int) $rawObservation['counted_quantity_base'];
        }

        return $observations;
    }
}
