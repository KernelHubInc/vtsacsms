<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
