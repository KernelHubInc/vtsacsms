<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use Carbon\CarbonImmutable;

final readonly class RatingInput
{
    public function __construct(
        public int $energyWh,
        public int $durationSeconds,
        public int $parkingSeconds,
        public int $idleSeconds,
        public CarbonImmutable $startedAt,
    ) {}
}
