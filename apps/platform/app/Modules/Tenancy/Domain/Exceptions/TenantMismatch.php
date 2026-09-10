<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Exceptions;

use LogicException;

final class TenantMismatch extends LogicException
{
    public function __construct()
    {
        parent::__construct('The resource does not belong to the active tenant.');
    }
}
