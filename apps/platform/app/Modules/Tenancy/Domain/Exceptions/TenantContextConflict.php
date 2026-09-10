<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Exceptions;

use LogicException;

final class TenantContextConflict extends LogicException
{
    public function __construct()
    {
        parent::__construct('A different tenant context is already active.');
    }
}
