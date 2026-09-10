<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Exceptions;

use LogicException;

final class MissingTenantContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('A verified tenant context is required for this operation.');
    }
}
