<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\ActorType;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

final readonly class TenantContext
{
    public function __construct(
        public string $tenantId,
        public ActorType $actorType,
        public ?string $actorId,
        public string $correlationId,
    ) {
        if (! Ulid::isValid($tenantId)) {
            throw new InvalidArgumentException('Tenant context requires a valid tenant ULID.');
        }

        if ($actorId !== null && ! Ulid::isValid($actorId)) {
            throw new InvalidArgumentException('Tenant context actor must be a valid ULID.');
        }

        if (! Ulid::isValid($correlationId)) {
            throw new InvalidArgumentException('Tenant context correlation ID must be a valid ULID.');
        }
    }
}
