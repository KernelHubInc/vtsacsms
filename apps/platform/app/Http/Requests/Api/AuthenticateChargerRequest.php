<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AuthenticateChargerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = config('services.ocpp_gateway.token');

        return is_string($token) && $token !== '' && hash_equals($token, (string) $this->bearerToken());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identity' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'password' => ['required', 'string', 'max:1024'],
            'protocol' => ['required', 'in:ocpp1.6,ocpp2.0.1'],
        ];
    }

    protected function failedAuthorization(): void
    {
        $this->reject(403);
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->reject(422);
    }

    private function reject(int $status): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'CHARGER_AUTHENTICATION_FAILED',
            'message' => 'Charger authentication could not be completed.',
            'correlation_id' => $this->attributes->get('correlation_id'),
        ]], $status)->header('Cache-Control', 'no-store'));
    }
}
