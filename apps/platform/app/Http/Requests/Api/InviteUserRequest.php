<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'organization_id' => ['nullable', 'ulid'],
            'role_id' => ['required', 'ulid'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'scope_id' => ['nullable', 'ulid', 'required_unless:scope_type,tenant'],
        ];
    }
}
