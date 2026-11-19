<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\Support\Application\SupportIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestSupportCode extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('support.enabled');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:254'], 'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(SupportIntake::CATEGORIES))], 'description' => ['required', 'string', 'min:10', 'max:5000'],
            'station_id' => ['nullable', 'ulid'], 'company_website' => ['nullable', 'string', 'max:0']];
    }
}
