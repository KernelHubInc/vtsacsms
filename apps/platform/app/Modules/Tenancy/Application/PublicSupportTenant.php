<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Str;

final readonly class PublicSupportTenant
{
    public function id(): string
    {
        $id = config('support.public_tenant_id');
        abort_unless(is_string($id) && Str::isUlid($id), 503, 'Support is temporarily unavailable.');
        abort_unless(Tenant::query()->whereKey($id)->where('status', 'active')->exists(), 503, 'Support is temporarily unavailable.');

        return $id;
    }
}
