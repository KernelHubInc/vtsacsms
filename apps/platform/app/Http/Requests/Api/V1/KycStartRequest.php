<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KycStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $document = config('kyc.documents.'.(string) $this->input('document_type'), []);
        $rules = [
            'idempotency_key' => ['required', 'ulid'],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', Rule::in([config('kyc.consent_version')])],
            'document_type' => ['required', Rule::in(array_keys(config('kyc.documents')))],
            'personal' => ['required', 'array:full_name,birth_date,document_number,expiration_date,nationality,issuing_country'],
            'personal.full_name' => ['required', 'string', 'min:2', 'max:180'],
        ];
        foreach (['birth_date', 'document_number', 'expiration_date', 'nationality', 'issuing_country'] as $field) {
            $rules['personal.'.$field] = [($document[$field] ?? false) ? 'required' : 'nullable', 'string', 'max:80'];
        }
        $rules['personal.birth_date'][] = 'date_format:Y-m-d';
        $rules['personal.birth_date'][] = 'before:today';
        $rules['personal.expiration_date'][] = 'date_format:Y-m-d';
        $rules['personal.nationality'][] = 'regex:/^[A-Z]{2}$/';
        $rules['personal.issuing_country'][] = 'regex:/^[A-Z]{2}$/';

        return $rules;
    }
}
