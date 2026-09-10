<?php

declare(strict_types=1);

namespace App\Foundation\Features;

use LogicException;

final class FeatureFlags
{
    public const MILESTONE_TWO_MESSAGE = 'This feature will be available in Milestone 2.';

    public function enabled(Feature $feature): bool
    {
        return (bool) config('features.'.$feature->value, false);
    }

    public function disabled(Feature $feature): bool
    {
        return ! $this->enabled($feature);
    }

    public function assertProductionSafe(): void
    {
        if (app()->environment('production') && $this->enabled(Feature::DemoMode)) {
            throw new LogicException('FEATURE_DEMO_MODE must never be enabled in production.');
        }
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return collect(Feature::cases())
            ->mapWithKeys(fn (Feature $feature): array => [$feature->value => $this->enabled($feature)])
            ->all();
    }
}
