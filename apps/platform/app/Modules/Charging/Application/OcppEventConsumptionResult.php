<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

final readonly class OcppEventConsumptionResult
{
    public function __construct(public string $outcome, public bool $duplicate, public ?string $errorCode = null) {}
}
