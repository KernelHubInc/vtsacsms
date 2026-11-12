<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmAccountDeletionCode extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['account_deletion_code' => ['required', 'string', 'regex:/^[0-9]{8}$/']];
    }
}
