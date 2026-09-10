<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queue;

use RuntimeException;

final class InvalidTenantJobContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The queued job has an invalid, inactive, or unauthorized tenant context.');
    }
}
