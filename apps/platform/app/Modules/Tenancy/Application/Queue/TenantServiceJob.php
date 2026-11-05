<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queue;

interface TenantServiceJob extends TenantAwareJob
{
    /** Called inside the active tenant context, before the handler can run. */
    public function serviceContextIsAuthorized(): bool;
}
