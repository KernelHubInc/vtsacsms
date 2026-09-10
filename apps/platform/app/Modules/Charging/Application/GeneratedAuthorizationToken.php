<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\Models\AuthorizationToken;

final readonly class GeneratedAuthorizationToken
{
    public function __construct(public AuthorizationToken $model, public string $plainText) {}
}
