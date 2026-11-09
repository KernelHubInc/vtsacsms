<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class GoogleSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:8192'],
            'tenant_id' => ['required', 'ulid'],
            'device_name' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:1024'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'invalid_request', 'message' => 'Google sign-in could not be started. Please try again.',
            'correlation_id' => $this->attributes->get('correlation_id'), 'errors' => [],
        ]], 422)->header('Cache-Control', 'no-store'));
    }
}
