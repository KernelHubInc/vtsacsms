<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Assets\Application\ConnectorSnapshot;
use App\Modules\Tariffs\Domain\Models\TariffDiscount;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use Carbon\CarbonImmutable;
use JsonException;

final class TariffSnapshotFactory
{
    /**
     * @return array{snapshot: array<string, mixed>, hash: string}
     *
     * @throws JsonException
     */
    public function create(
        TariffVersion $version,
        ConnectorSnapshot $connector,
        CarbonImmutable $selectedAt,
        ?string $promotionCode = null,
    ): array {
        $discounts = $version->discounts
            ->filter(fn (TariffDiscount $discount): bool => $this->discountApplies($discount, $selectedAt, $promotionCode))
            ->map(fn (TariffDiscount $discount): array => [
                'id' => (string) $discount->getKey(),
                'name' => (string) $discount->name,
                'code' => $discount->code,
                'kind' => $discount->kind->value,
                'value' => (int) $discount->value,
            ])->values()->all();

        $snapshot = [
            'pricing_status' => 'selected',
            'tariff_id' => (string) $version->tariff_id,
            'tariff_version_id' => (string) $version->getKey(),
            'version' => (int) $version->version,
            'name' => (string) $version->tariff->name,
            'currency' => (string) $version->tariff->currency,
            'tax_treatment' => $version->tax_treatment->value,
            'tax_rate_basis_points' => $version->tax_rate_basis_points === null ? null : (int) $version->tax_rate_basis_points,
            'minimum_fee_minor' => $version->minimum_fee_minor === null ? null : (int) $version->minimum_fee_minor,
            'maximum_fee_minor' => $version->maximum_fee_minor === null ? null : (int) $version->maximum_fee_minor,
            'effective_from' => $version->effective_from->utc()->toISOString(),
            'effective_to' => $version->effective_to?->utc()->toISOString(),
            'selected_at' => $selectedAt->utc()->toISOString(),
            'timezone' => (string) $version->timezone,
            'scope' => [
                'operator_id' => $version->operator_id,
                'site_id' => $version->site_id,
                'connector_id' => $version->connector_id,
            ],
            'subject' => [
                'operator_id' => $connector->operatorId,
                'site_id' => $connector->siteId,
                'connector_id' => $connector->connectorId,
            ],
            'components' => $version->components->map(fn ($component): array => [
                'id' => (string) $component->getKey(),
                'dimension' => $component->dimension->value,
                'price_minor' => (int) $component->price_minor,
                'unit_quantity' => (int) $component->unit_quantity,
                'day_of_week_mask' => (int) $component->day_of_week_mask,
                'starts_at_local' => $component->starts_at_local,
                'ends_at_local' => $component->ends_at_local,
                'priority' => (int) $component->priority,
            ])->values()->all(),
            'discounts' => $discounts,
        ];

        return ['snapshot' => $snapshot, 'hash' => $this->hash($snapshot)];
    }

    /** @return array{snapshot: array<string, mixed>, hash: string} */
    public function missing(ConnectorSnapshot $connector, CarbonImmutable $selectedAt): array
    {
        $snapshot = [
            'pricing_status' => 'missing',
            'tariff_id' => null,
            'tariff_version_id' => null,
            'currency' => null,
            'selected_at' => $selectedAt->utc()->toISOString(),
            'timezone' => $connector->timezone,
            'subject' => [
                'operator_id' => $connector->operatorId,
                'site_id' => $connector->siteId,
                'connector_id' => $connector->connectorId,
            ],
            'components' => [],
            'discounts' => [],
        ];

        return ['snapshot' => $snapshot, 'hash' => $this->hash($snapshot)];
    }

    private function discountApplies(
        TariffDiscount $discount,
        CarbonImmutable $at,
        ?string $promotionCode,
    ): bool {
        $isSelected = $discount->is_automatic
            || ($promotionCode !== null && $discount->code !== null && hash_equals(mb_strtoupper($discount->code), mb_strtoupper($promotionCode)));

        return $isSelected
            && ($discount->effective_from === null || $discount->effective_from->lte($at))
            && ($discount->effective_to === null || $discount->effective_to->gt($at));
    }

    /** @param array<string, mixed> $snapshot */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
