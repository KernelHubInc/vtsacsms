<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closing = 'closing';
    case Closed = 'closed';
}
