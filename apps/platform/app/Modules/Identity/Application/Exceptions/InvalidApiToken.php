<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class InvalidApiToken extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid')
    {
        parent::__construct('The API token is invalid or no longer usable.');
    }
}
