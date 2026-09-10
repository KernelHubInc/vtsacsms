<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\AssetComponent;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Integrations\Application\OutboxRecorder;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final readonly class EloquentAssetMaintenanceContract implements AssetMaintenanceContract
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function assertBelongsToSite(string $assetType, string $assetId, string $siteId): void
    {
        $asset = $this->asset($assetType, $assetId);
        $actualSiteId = match ($assetType) {
            'station' => (string) $asset->getAttribute('site_id'),
            'evse' => (string) $asset->getRelation('station')->getAttribute('site_id'),
            'connector' => (string) $asset->getRelation('evse')->getRelation('station')->getAttribute('site_id'),
            'component' => (string) $asset->getRelation('connector')->getRelation('evse')
                ->getRelation('station')->getAttribute('site_id'),
            default => throw new DomainException('Unsupported maintainable asset type.'),
        };

        if (! hash_equals($siteId, $actualSiteId)) {
            throw new DomainException('The selected asset does not belong to the work-order site.');
        }
    }

    public function restrictForMaintenance(
        string $assetType,
        string $assetId,
        string $workOrderId,
        string $reasonCode,
    ): void {
        $asset = $this->asset($assetType, $assetId);
        $state = $asset->getAttribute('lifecycle_status');
        if ($state === AssetLifecycleStatus::Maintenance) {
            return;
        }
        if ($state !== AssetLifecycleStatus::Active) {
            throw new DomainException('Only an active asset can be restricted for maintenance.');
        }

        $asset->setAttribute('lifecycle_status', AssetLifecycleStatus::Maintenance);
        $asset->save();
        $this->outbox->record('assets.asset.restricted.v1', $assetType, $assetId, [
            'work_order_id' => $workOrderId,
            'reason_code' => $reasonCode,
        ]);
    }

    public function returnToService(
        string $assetType,
        string $assetId,
        string $workOrderId,
        string $reasonCode,
    ): void {
        $asset = $this->asset($assetType, $assetId);
        $state = $asset->getAttribute('lifecycle_status');
        if ($state === AssetLifecycleStatus::Active) {
            return;
        }
        if ($state !== AssetLifecycleStatus::Maintenance) {
            throw new DomainException('Only an asset in maintenance can be returned to service.');
        }

        $asset->setAttribute('lifecycle_status', AssetLifecycleStatus::Active);
        $asset->save();
        $this->outbox->record('assets.asset.returned_to_service.v1', $assetType, $assetId, [
            'work_order_id' => $workOrderId,
            'reason_code' => $reasonCode,
        ]);
    }

    private function asset(string $assetType, string $assetId): Model
    {
        return match ($assetType) {
            'station' => ChargingStation::query()->whereKey($assetId)->lockForUpdate()->firstOrFail(),
            'evse' => Evse::query()->with('station')->whereKey($assetId)->lockForUpdate()->firstOrFail(),
            'connector' => Connector::query()->with('evse.station')->whereKey($assetId)->lockForUpdate()->firstOrFail(),
            'component' => AssetComponent::query()->with('connector.evse.station')
                ->whereKey($assetId)->lockForUpdate()->firstOrFail(),
            default => throw new DomainException('Unsupported maintainable asset type.'),
        };
    }
}
