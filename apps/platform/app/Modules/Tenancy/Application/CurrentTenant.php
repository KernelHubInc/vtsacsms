<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextConflict;

final class CurrentTenant
{
    private ?TenantContext $context = null;

    public function establish(TenantContext $context): void
    {
        if ($this->context !== null && $this->context !== $context) {
            throw new TenantContextConflict;
        }

        $this->context = $context;
    }

    public function get(): TenantContext
    {
        return $this->context ?? throw new MissingTenantContext;
    }

    public function getOrNull(): ?TenantContext
    {
        return $this->context;
    }

    public function has(): bool
    {
        return $this->context !== null;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function run(TenantContext $context, callable $operation): mixed
    {
        $this->establish($context);

        try {
            return $operation();
        } finally {
            $this->clear();
        }
    }
}
