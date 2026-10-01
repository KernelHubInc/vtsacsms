<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class KycLiveFrameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:2048'],
        ];
    }
}
