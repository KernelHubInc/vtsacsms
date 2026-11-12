<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestAccountDeletionCode extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:254']];
    }
}
