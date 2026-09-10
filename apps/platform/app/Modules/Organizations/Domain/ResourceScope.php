<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

final readonly class ResourceScope
{
    public function __construct(
        public ScopeType $type,
        public ?string $id = null,
    ) {
        if ($type === ScopeType::Tenant && $id !== null) {
            throw new InvalidArgumentException('Tenant scope cannot have a resource identifier.');
        }

        if ($type !== ScopeType::Tenant && ! Ulid::isValid((string) $id)) {
            throw new InvalidArgumentException('Resource scope requires a valid ULID.');
        }
    }

    public static function tenant(): self
    {
        return new self(ScopeType::Tenant);
    }
}
