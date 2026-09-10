<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class TariffEngineTest extends TestCase
{
    public function test_rates_all_dimensions_discounts_exclusive_tax_and_integer_rounding(): void
    {
        $snapshot = $this->snapshot([
            $this->component('energy', 30, 1000),
            $this->component('time', 2, 60),
            $this->component('session', 50, 1),
            $this->component('parking', 1, 60),
            $this->component('idle', 4, 60),
        ], [
            ['name' => 'Ten percent', 'kind' => 'percentage', 'value' => 1000],
            ['name' => 'Fixed promotion', 'kind' => 'fixed', 'value' => 9],
        ], taxTreatment: 'exclusive', taxRate: 1200);

        $result = (new TariffEngine)->rate($snapshot, new RatingInput(
            energyWh: 1500,
            durationSeconds: 600,
            parkingSeconds: 120,
            idleSeconds: 60,
            startedAt: CarbonImmutable::parse('2026-07-22T10:00:00Z'),
        ));

        self::assertSame(121, $result->subtotalMinor);
        self::assertSame(21, $result->discountMinor);
        self::assertSame(12, $result->taxMinor);
        self::assertSame(112, $result->totalMinor);
        self::assertSame('USD', $result->currency);
    }

    public function test_time_of_day_day_of_week_priority_and_fee_caps_are_deterministic(): void
    {
        $snapshot = $this->snapshot([
            $this->component('energy', 30, 1000),
            $this->component('energy', 50, 1000, dayMask: 4, starts: '18:00:00', ends: '22:00:00', priority: 10),
        ], minimum: 100, maximum: 120);

        $peak = (new TariffEngine)->rate($snapshot, new RatingInput(
            energyWh: 1000,
            durationSeconds: 0,
            parkingSeconds: 0,
            idleSeconds: 0,
            startedAt: CarbonImmutable::parse('2026-07-22T19:00:00Z'),
        ));
        self::assertSame(50, $peak->subtotalMinor);
        self::assertSame(100, $peak->totalMinor);

        $capped = (new TariffEngine)->rate($snapshot, new RatingInput(
            energyWh: 5000,
            durationSeconds: 0,
            parkingSeconds: 0,
            idleSeconds: 0,
            startedAt: CarbonImmutable::parse('2026-07-22T19:00:00Z'),
        ));
        self::assertSame(250, $capped->subtotalMinor);
        self::assertSame(120, $capped->totalMinor);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  list<array<string, mixed>>  $discounts
     * @return array<string, mixed>
     */
    private function snapshot(
        array $components,
        array $discounts = [],
        string $taxTreatment = 'inclusive',
        ?int $taxRate = null,
        ?int $minimum = null,
        ?int $maximum = null,
    ): array {
        return [
            'pricing_status' => 'selected',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'tax_treatment' => $taxTreatment,
            'tax_rate_basis_points' => $taxRate,
            'minimum_fee_minor' => $minimum,
            'maximum_fee_minor' => $maximum,
            'components' => $components,
            'discounts' => $discounts,
        ];
    }

    /** @return array<string, mixed> */
    private function component(
        string $dimension,
        int $price,
        int $unit,
        int $dayMask = 127,
        ?string $starts = null,
        ?string $ends = null,
        int $priority = 0,
    ): array {
        return [
            'dimension' => $dimension,
            'price_minor' => $price,
            'unit_quantity' => $unit,
            'day_of_week_mask' => $dayMask,
            'starts_at_local' => $starts,
            'ends_at_local' => $ends,
            'priority' => $priority,
        ];
    }
}
