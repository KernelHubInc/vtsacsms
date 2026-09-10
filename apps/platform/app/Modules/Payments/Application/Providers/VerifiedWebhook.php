<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

use Carbon\CarbonImmutable;

final readonly class VerifiedWebhook
{
    /** @param array<string, scalar|null> $normalizedPayload */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $resourceReference,
        public ?CarbonImmutable $createdAt,
        public array $normalizedPayload,
    ) {}
}
