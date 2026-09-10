<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

final readonly class StockAvailability
{
    public function __construct(
        public int $onHandBase,
        public int $reservedBase,
        public ?int $eligibleOnHandBase = null,
    ) {}

    public function availableBase(): int
    {
        return ($this->eligibleOnHandBase ?? $this->onHandBase) - $this->reservedBase;
    }

    /** @return array{on_hand_base: int, reserved_base: int, available_base: int} */
    public function toArray(): array
    {
        return [
            'on_hand_base' => $this->onHandBase,
            'reserved_base' => $this->reservedBase,
            'available_base' => $this->availableBase(),
        ];
    }
}
