<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KycUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in($this->routeIs('api.v1.kyc.selfie') ? ['selfie'] : ['front', 'back'])],
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:'.config('kyc.max_file_kb')],
        ];
    }
}
