<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Locations\Domain\Models\Site;
use Database\Factories\Modules\Assets\Domain\Models\ChargingStationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $site_id
 * @property string|null $charger_model_id
 * @property AssetLifecycleStatus $lifecycle_status
 * @property string $charge_point_identity
 * @property OcppVersion|null $ocppVersion
 * @property Site $site
 */
final class ChargingStation extends TenantAssetModel
{
    /** @use HasFactory<ChargingStationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lifecycle_status' => AssetLifecycleStatus::class,
            'is_public' => 'boolean',
            'commissioned_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<ChargerModel, $this> */
    public function chargerModel(): BelongsTo
    {
        return $this->belongsTo(ChargerModel::class);
    }

    /** @return BelongsTo<OcppVersion, $this> */
    public function ocppVersion(): BelongsTo
    {
        return $this->belongsTo(OcppVersion::class);
    }

    /** @return BelongsTo<OcppSecurityProfile, $this> */
    public function securityProfile(): BelongsTo
    {
        return $this->belongsTo(OcppSecurityProfile::class, 'ocpp_security_profile_id');
    }

    /** @return HasMany<Evse, $this> */
    public function evses(): HasMany
    {
        return $this->hasMany(Evse::class);
    }

    /** @return HasMany<ChargingStationFirmwareHistory, $this> */
    public function firmwareHistory(): HasMany
    {
        return $this->hasMany(ChargingStationFirmwareHistory::class);
    }

    /** @return HasMany<ChargingStationPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ChargingStationPhoto::class);
    }
}
