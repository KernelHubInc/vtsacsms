<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Assets\Domain\Models\ChargingStation;
use Illuminate\Database\Eloquent\Builder;

final readonly class SupportStationQuery
{
    /** @return array<string,string> */
    public function options(): array
    {
        return $this->published()->orderBy('name')->limit(250)->pluck('name', 'id')->all();
    }

    public function site(string $stationId): string
    {
        return (string) $this->published()->whereKey($stationId)->firstOrFail()->site_id;
    }

    /** @return Builder<ChargingStation> */
    private function published(): Builder
    {
        return ChargingStation::query()->where('is_public', true)->where('lifecycle_status', 'active')
            ->whereHas('site', fn ($query) => $query->where('is_public', true)->where('is_active', true)->where('lifecycle_status', 'active'));
    }
}
