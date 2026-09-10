<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queue;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\TenantContext;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

final readonly class TenantJobEnvelope
{
    public function __construct(
        public string $jobId,
        public int $schemaVersion,
        public string $tenantId,
        public ActorType $actorType,
        public ?string $actorId,
        public string $correlationId,
        public ?string $causationId = null,
    ) {
        foreach ([$jobId, $tenantId, $correlationId] as $id) {
            if (! Ulid::isValid($id)) {
                throw new InvalidArgumentException('Tenant job envelope identifiers must be ULIDs.');
            }
        }

        if ($actorId !== null && ! Ulid::isValid($actorId)) {
            throw new InvalidArgumentException('Tenant job actor ID must be a ULID.');
        }

        if ($causationId !== null && ! Ulid::isValid($causationId)) {
            throw new InvalidArgumentException('Tenant job causation ID must be a ULID.');
        }

        if ($schemaVersion !== 1) {
            throw new InvalidArgumentException('Unsupported tenant job envelope schema version.');
        }
    }

    public function toTenantContext(): TenantContext
    {
        return new TenantContext(
            tenantId: $this->tenantId,
            actorType: $this->actorType,
            actorId: $this->actorId,
            correlationId: $this->correlationId,
        );
    }
}
