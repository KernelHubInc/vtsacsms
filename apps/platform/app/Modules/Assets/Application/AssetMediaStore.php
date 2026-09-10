<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\ChargingStationPhoto;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SitePhoto;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class AssetMediaStore
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function storeSitePhoto(Site $site, UploadedFile $file, string $altText, bool $isPublic = false): SitePhoto
    {
        $path = $this->store($site->tenant_id, 'sites/'.(string) $site->getKey(), $file);
        $photo = SitePhoto::query()->create($this->attributes($site->tenant_id, $site->getKey(), 'site_id', $file, $path, $altText, $isPublic));
        $this->audit->record(new AuditEntry('locations.site_photo.created', 'site_photo', (string) $photo->getKey(), AuditResult::Succeeded, after: ['site_id' => $site->getKey(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'is_public' => $isPublic]));

        return $photo;
    }

    public function storeStationPhoto(ChargingStation $station, UploadedFile $file, string $altText, bool $isPublic = false): ChargingStationPhoto
    {
        $path = $this->store($station->tenant_id, 'stations/'.(string) $station->getKey(), $file);
        $photo = ChargingStationPhoto::query()->create($this->attributes($station->tenant_id, $station->getKey(), 'charging_station_id', $file, $path, $altText, $isPublic));
        $this->audit->record(new AuditEntry('assets.station_photo.created', 'charging_station_photo', (string) $photo->getKey(), AuditResult::Succeeded, after: ['charging_station_id' => $station->getKey(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'is_public' => $isPublic]));

        return $photo;
    }

    private function store(string $tenantId, string $directory, UploadedFile $file): string
    {
        $path = $file->store("tenants/{$tenantId}/{$directory}", ['disk' => 's3', 'visibility' => 'private']);
        if ($path === false) {
            throw new RuntimeException('Object storage rejected the upload.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function attributes(string $tenantId, mixed $ownerId, string $ownerKey, UploadedFile $file, string $path, string $altText, bool $isPublic): array
    {
        return [
            'tenant_id' => $tenantId, $ownerKey => $ownerId, 'disk' => 's3', 'path' => $path,
            'mime_type' => (string) $file->getMimeType(), 'size_bytes' => $file->getSize(),
            'alt_text' => $altText, 'is_public' => $isPublic,
        ];
    }
}
