<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\ApiToken;

final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        public string $plainTextToken,
    ) {}
}
