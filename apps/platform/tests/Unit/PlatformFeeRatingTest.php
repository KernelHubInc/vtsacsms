<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformFeeRatingTest extends TestCase
{
    public function test_fee_tax_does_not_inherit_the_charging_tax_treatment(): void
    {
        $snapshot = ['pricing_status' => 'selected', 'currency' => 'PHP', 'timezone' => 'Asia/Manila',
            'tax_treatment' => 'exclusive', 'tax_rate_basis_points' => 1200,
            'components' => [['dimension' => 'energy', 'price_minor' => 1000, 'unit_quantity' => 1000, 'priority' => 1, 'day_of_week_mask' => 127]],
            'platform_fee' => ['enabled' => true, 'amount_minor' => 500, 'waiver_threshold_minor' => 10000, 'tax_rate_basis_points' => 1200, 'beneficiary' => 'Test platform']];
        $result = (new TariffEngine)->rate($snapshot, new RatingInput(10000, 100, 0, 0, CarbonImmutable::parse('2026-11-01T00:00:00Z')));
        self::assertSame(11700, $result->totalMinor);
        self::assertSame(10446, $result->subtotalMinor);
        self::assertSame(1254, $result->taxMinor);
        self::assertSame(11200, $result->toArray()['charging_total_minor']);
    }

    /** @return list<array{int, int, int, int}> */
    public static function feeScenarios(): array
    {
        return [[0, 0, 0, 0], [9999, 9999, 0, 0], [10000, 10500, 500, 54], [32000, 32500, 500, 54]];
    }

    #[DataProvider('feeScenarios')]
    public function test_fee_is_inclusive_waived_below_threshold_and_never_charged_without_usage(int $wh, int $total, int $fee, int $feeTax): void
    {
        $snapshot = ['pricing_status' => 'selected', 'currency' => 'PHP', 'timezone' => 'Asia/Manila',
            'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => 0,
            'components' => [['dimension' => 'energy', 'price_minor' => 1000, 'unit_quantity' => 1000, 'priority' => 1, 'day_of_week_mask' => 127]],
            'platform_fee' => ['enabled' => true, 'amount_minor' => 500, 'waiver_threshold_minor' => 10000, 'tax_rate_basis_points' => 1200, 'beneficiary' => 'Test platform']];
        $engine = new TariffEngine;
        $result = $engine->rate($snapshot, new RatingInput($wh, 100, 0, 0, CarbonImmutable::parse('2026-11-01T00:00:00Z')));
        self::assertSame($total, $result->totalMinor);
        self::assertSame($fee, $result->platformFeeMinor);
        self::assertSame($feeTax, $result->platformFeeTaxMinor);
        self::assertSame($wh, $result->toArray()['charging_total_minor']);
        $snapshot['platform_fee']['enabled'] = false;
        self::assertSame(32000, $engine->rate($snapshot, new RatingInput(32000, 100, 0, 0, CarbonImmutable::parse('2026-11-01T00:00:00Z')))->totalMinor);
    }
}
