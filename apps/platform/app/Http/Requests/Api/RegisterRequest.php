<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email')]);
        if ($this->has('first_name') || $this->has('last_name')) {
            $names = array_map(static fn ($value): string => is_string($value) ? trim($value) : '', [$this->input('first_name'), $this->input('middle_name'), $this->input('last_name')]);
            $this->merge(['name' => implode(' ', array_filter($names, static fn (string $value): bool => $value !== ''))]);
        }
        if (is_string($this->input('plate_number'))) {
            $this->merge(['plate_number' => strtoupper(trim($this->input('plate_number')))]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        if (isset($validator->failed()['email']['Unique'])) {
            unset($errors['email']);
        }
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'registration_failed', 'message' => 'Account creation was not successful.',
            'correlation_id' => $this->attributes->get('correlation_id'), 'errors' => $errors,
        ]], 422));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:240'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('UTC')->subYearsNoOverflow(18)->format('Y-m-d')],
            'plate_pending' => ['required', 'boolean'],
            'plate_number' => ['nullable', 'required_if:plate_pending,false', 'prohibited_if:plate_pending,true', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9 -]*$/'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'tenant_id' => ['required', 'ulid'],
        ];
    }
}
