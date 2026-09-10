<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Models\Operator;

final class OrganizationScopeQuery
{
    public function operatorExists(string $operatorId): bool
    {
        return Operator::query()->whereKey($operatorId)->exists();
    }
}
