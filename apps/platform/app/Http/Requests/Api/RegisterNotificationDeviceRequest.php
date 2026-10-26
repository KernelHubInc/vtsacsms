<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterNotificationDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'string', 'min:10', 'max:512'],
            'platform' => ['required', 'in:android'],
        ];
    }
}
