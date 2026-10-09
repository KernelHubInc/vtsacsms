<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

final class ResolveChargerRequest extends AuthenticateChargerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['password']);

        return $rules;
    }
}
