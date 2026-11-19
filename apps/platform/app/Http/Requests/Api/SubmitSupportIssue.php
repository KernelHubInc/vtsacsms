<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Support\Application\SupportIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitSupportIssue extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['session_id' => ['required', 'ulid'], 'category' => ['required', Rule::in(array_keys(SupportIntake::CATEGORIES))],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/']];
    }
}
