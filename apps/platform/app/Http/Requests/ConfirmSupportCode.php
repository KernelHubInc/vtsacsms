<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmSupportCode extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('support.enabled');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['support_code' => ['required', 'string', 'regex:/^[0-9]{8}$/']];
    }
}
