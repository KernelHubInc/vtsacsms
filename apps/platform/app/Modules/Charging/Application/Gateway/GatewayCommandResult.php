<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application\Gateway;

final readonly class GatewayCommandResult
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        public string $status,
        public ?array $response,
        public ?string $error,
    ) {}
}
