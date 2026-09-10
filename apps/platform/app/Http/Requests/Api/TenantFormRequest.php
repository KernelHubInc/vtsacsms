<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;

abstract class TenantFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function tenantId(): string
    {
        return app(CurrentTenant::class)->get()->tenantId;
    }
}
