<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Assets\Application\ConnectorSnapshot;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tariffs\Domain\TariffStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class TariffSelector
{
    public function select(
        ConnectorSnapshot $connector,
        CarbonImmutable $at,
        ?string $requestedVersionId = null,
    ): ?TariffVersion {
        $query = TariffVersion::query()
            ->with(['tariff', 'components', 'discounts'])
            ->where('status', TariffStatus::Published->value)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('operator_id')
                ->orWhere('operator_id', $connector->operatorId))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('site_id')
                ->orWhere('site_id', $connector->siteId))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('connector_id')
                ->orWhere('connector_id', $connector->connectorId));

        if ($requestedVersionId !== null) {
            return $query->whereKey($requestedVersionId)->first();
        }

        return $query
            ->orderByRaw('CASE WHEN connector_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN site_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN operator_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }
}
