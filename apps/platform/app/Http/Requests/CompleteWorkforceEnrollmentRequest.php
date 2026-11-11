<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CompleteWorkforceEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $token = $this->input('enrollment_token');

        return [
            'enrollment_token' => ['required', 'string', 'regex:/^(?:[a-f0-9]{64}|CSMS-[a-f0-9]{32})$/D'],
            'email' => [Rule::requiredIf(is_string($token) && str_starts_with($token, 'CSMS-')), 'nullable', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'confirmed', 'different:enrollment_token', 'min:12', Password::defaults(), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72) {
                    $fail('Use a shorter password.');
                }
            }],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'enrollment_validation_failed', 'message' => 'Check the setup details and try again.',
            'correlation_id' => $this->attributes->get('correlation_id'), 'fields' => $validator->errors()->toArray(),
        ]], 422)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer'));
    }
}
