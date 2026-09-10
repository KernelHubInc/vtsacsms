<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\DiscountKind;
use App\Modules\Tariffs\Domain\TariffDimension;
use App\Modules\Tariffs\Domain\TaxTreatment;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OverflowException;

final class TariffEngine
{
    /** @param array<string, mixed> $snapshot */
    public function rate(array $snapshot, RatingInput $input): RatingResult
    {
        $this->assertNonNegative($input);
        if (($snapshot['pricing_status'] ?? null) !== 'selected') {
            return new RatingResult(null, 0, 0, 0, 0, [], []);
        }

        $currency = is_string($snapshot['currency'] ?? null) ? $snapshot['currency'] : null;
        $localStart = $input->startedAt->setTimezone((string) ($snapshot['timezone'] ?? 'UTC'));
        $components = is_array($snapshot['components'] ?? null) ? $snapshot['components'] : [];
        $selected = $this->selectComponents(array_values($components), $localStart);
        $lines = [];
        $subtotal = 0;

        foreach ($selected as $component) {
            $dimension = TariffDimension::from((string) $component['dimension']);
            $quantity = match ($dimension) {
                TariffDimension::Energy => $input->energyWh,
                TariffDimension::Time => $input->durationSeconds,
                TariffDimension::Parking => $input->parkingSeconds,
                TariffDimension::Idle => $input->idleSeconds,
                TariffDimension::Session => 1,
            };
            $amount = $this->multiplyAndDivide(
                (int) $component['price_minor'],
                $quantity,
                (int) $component['unit_quantity'],
            );
            $subtotal = $this->safeAdd($subtotal, $amount);
            $lines[] = [
                'dimension' => $dimension->value,
                'quantity' => $quantity,
                'unit_quantity' => (int) $component['unit_quantity'],
                'price_minor' => (int) $component['price_minor'],
                'amount_minor' => $amount,
            ];
        }

        [$discountMinor, $discountLines] = $this->discounts($snapshot, $subtotal);
        $net = max(0, $subtotal - $discountMinor);
        $taxTreatment = TaxTreatment::from((string) $snapshot['tax_treatment']);
        $taxRate = (int) ($snapshot['tax_rate_basis_points'] ?? 0);
        $taxMinor = match ($taxTreatment) {
            TaxTreatment::Exclusive => $this->multiplyAndDivide($net, $taxRate, 10_000),
            TaxTreatment::Inclusive => $taxRate === 0 ? 0 : $this->multiplyAndDivide($net, $taxRate, 10_000 + $taxRate),
        };
        $total = $taxTreatment === TaxTreatment::Exclusive ? $this->safeAdd($net, $taxMinor) : $net;
        $minimum = $snapshot['minimum_fee_minor'] ?? null;
        $maximum = $snapshot['maximum_fee_minor'] ?? null;
        if (is_int($minimum)) {
            $total = max($total, $minimum);
        }
        if (is_int($maximum)) {
            $total = min($total, $maximum);
        }

        return new RatingResult(
            $currency,
            $subtotal,
            $discountMinor,
            $taxMinor,
            $total,
            $lines,
            $discountLines,
        );
    }

    /**
     * @param  list<mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function selectComponents(array $components, CarbonImmutable $at): array
    {
        $selected = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! $this->componentMatches($component, $at)) {
                continue;
            }
            $dimension = (string) ($component['dimension'] ?? '');
            if (! isset($selected[$dimension]) || (int) $component['priority'] > (int) $selected[$dimension]['priority']) {
                $selected[$dimension] = $component;
            }
        }

        return array_values($selected);
    }

    /** @param array<string, mixed> $component */
    private function componentMatches(array $component, CarbonImmutable $at): bool
    {
        $dayMask = (int) ($component['day_of_week_mask'] ?? 0);
        if (($dayMask & (1 << ($at->dayOfWeekIso - 1))) === 0) {
            return false;
        }

        $starts = $component['starts_at_local'] ?? null;
        $ends = $component['ends_at_local'] ?? null;
        if (! is_string($starts) || ! is_string($ends)) {
            return $starts === null && $ends === null;
        }

        $time = $at->format('H:i:s');

        return $starts < $ends
            ? $time >= $starts && $time < $ends
            : $time >= $starts || $time < $ends;
    }

    /** @param array<string, mixed> $snapshot
     * @return array{int, list<array<string, int|string>>}
     */
    private function discounts(array $snapshot, int $subtotal): array
    {
        $remaining = $subtotal;
        $totalDiscount = 0;
        $lines = [];
        $discounts = is_array($snapshot['discounts'] ?? null) ? $snapshot['discounts'] : [];
        foreach ($discounts as $discount) {
            if (! is_array($discount)) {
                continue;
            }
            $kind = DiscountKind::from((string) $discount['kind']);
            $amount = match ($kind) {
                DiscountKind::Percentage => $this->multiplyAndDivide($remaining, (int) $discount['value'], 10_000),
                DiscountKind::Fixed => (int) $discount['value'],
            };
            $amount = min($remaining, $amount);
            $remaining -= $amount;
            $totalDiscount += $amount;
            $lines[] = [
                'name' => (string) $discount['name'],
                'kind' => $kind->value,
                'value' => (int) $discount['value'],
                'amount_minor' => $amount,
            ];
        }

        return [$totalDiscount, $lines];
    }

    private function multiplyAndDivide(int $left, int $right, int $denominator): int
    {
        if ($left < 0 || $right < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Tariff quantities and rates must be non-negative.');
        }
        if ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right)) {
            throw new OverflowException('Tariff calculation exceeds the supported integer range.');
        }
        $product = $left * $right;
        $quotient = intdiv($product, $denominator);
        $remainder = $product % $denominator;

        return $remainder >= intdiv($denominator + 1, 2) ? $quotient + 1 : $quotient;
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('Tariff calculation exceeds the supported integer range.');
        }

        return $left + $right;
    }

    private function assertNonNegative(RatingInput $input): void
    {
        if (min($input->energyWh, $input->durationSeconds, $input->parkingSeconds, $input->idleSeconds) < 0) {
            throw new InvalidArgumentException('Rating inputs must be non-negative integer measurements.');
        }
    }
}
