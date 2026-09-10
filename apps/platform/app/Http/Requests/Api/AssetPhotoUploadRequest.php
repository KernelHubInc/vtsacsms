<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class AssetPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'], 'alt_text' => ['required', 'string', 'max:240'], 'is_public' => ['sometimes', 'boolean']];
    }
}
