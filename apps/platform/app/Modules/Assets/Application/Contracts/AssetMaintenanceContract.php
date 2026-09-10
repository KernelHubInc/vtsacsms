<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application\Contracts;

interface AssetMaintenanceContract
{
    public function assertBelongsToSite(string $assetType, string $assetId, string $siteId): void;

    public function restrictForMaintenance(
        string $assetType,
        string $assetId,
        string $workOrderId,
        string $reasonCode,
    ): void;

    public function returnToService(
        string $assetType,
        string $assetId,
        string $workOrderId,
        string $reasonCode,
    ): void;
}
