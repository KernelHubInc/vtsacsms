<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Identity\Application\GoogleIdentity;
use App\Modules\Identity\Application\GoogleTokenVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class GoogleRegisterRequest extends RegisterRequest
{
    private GoogleIdentity $identity;

    protected function prepareForValidation(): void
    {
        // Bound token size and tenant syntax before accessing Google's public keys.
        $validator = Validator::make($this->only('id_token', 'tenant_id'), [
            'id_token' => ['required', 'string', 'max:8192'], 'tenant_id' => ['required', 'ulid'],
        ]);
        if ($validator->fails()) {
            $this->failedValidation($validator);
        }
        $this->identity = app(GoogleTokenVerifier::class)->verify((string) $this->input('id_token'), (string) $this->input('tenant_id'));
        $this->merge(['email' => $this->identity->email]);
        parent::prepareForValidation();
    }

    public function identity(): GoogleIdentity
    {
        return $this->identity;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['password']);
        $rules['id_token'] = ['required', 'string', 'max:8192'];
        $rules['device_name'] = ['required', 'string', 'max:120'];
        $rules['terms_accepted'] = ['accepted'];
        $rules['email'] = ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')];

        return $rules;
    }
}
