<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\ApiToken;

final readonly class AuthenticatedApiPrincipal
{
    public function __construct(
        public User $user,
        public ApiToken $token,
    ) {}
}
