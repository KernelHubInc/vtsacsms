<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queue;

interface TenantAwareJob
{
    public function tenantJobEnvelope(): TenantJobEnvelope;
}
