<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

final readonly class RatingResult
{
    /**
     * @param  list<array<string, int|string>>  $lines
     * @param  list<array<string, int|string>>  $discounts
     */
    public function __construct(
        public ?string $currency,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $lines,
        public array $discounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotalMinor,
            'discount_minor' => $this->discountMinor,
            'tax_minor' => $this->taxMinor,
            'total_minor' => $this->totalMinor,
            'lines' => $this->lines,
            'discounts' => $this->discounts,
        ];
    }
}
