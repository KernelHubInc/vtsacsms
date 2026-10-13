<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerChargingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'prepare' => ['charger_code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{6,120}$/'], 'vehicle_id' => ['nullable', 'ulid']],
            'start' => [
                'quote_id' => ['required', 'ulid'], 'connector_id' => ['required', 'ulid'],
                'payment_method_id' => ['required', 'in:prepaid_wallet'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/'],
            ],
            'stop' => ['idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/']],
            'cancel' => ['reason' => ['required', 'string', 'max:300']],
            default => [],
        };
    }
}
