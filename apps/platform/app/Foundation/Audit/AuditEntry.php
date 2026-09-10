<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public string $action,
        public string $targetType,
        public ?string $targetId,
        public AuditResult $result,
        public ?string $reason = null,
        public array $changes = [],
        public array $metadata = [],
        public ?array $before = null,
        public ?array $after = null,
        public ?string $sourceIp = null,
        public ?string $userAgent = null,
    ) {
        if ($action === '' || mb_strlen($action) > 160) {
            throw new InvalidArgumentException('Audit action must contain at most 160 characters.');
        }

        if ($targetType === '' || mb_strlen($targetType) > 80) {
            throw new InvalidArgumentException('Audit target type must contain at most 80 characters.');
        }

        if ($targetId !== null && ! Ulid::isValid($targetId)) {
            throw new InvalidArgumentException('Audit target ID must be a ULID.');
        }

        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Audit reason must contain at most 500 characters.');
        }

        if ($userAgent !== null && mb_strlen($userAgent) > 500) {
            throw new InvalidArgumentException('Audit user agent must contain at most 500 characters.');
        }
    }
}
