<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class WalletTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'], 'idempotency_key' => ['required', 'ulid']];
    }
}
