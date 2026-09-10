<?php

declare(strict_types=1);

namespace App\Modules\Locations\Domain\Models;

use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Database\Factories\Modules\Locations\Domain\Models\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string|null $operator_organization_id
 * @property string $timezone
 */
final class Site extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'operator_organization_id',
        'site_host_organization_id',
        'name',
        'code',
        'is_active',
        'public_slug',
        'description',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'country_id',
        'region_id',
        'province_id',
        'city_id',
        'barangay_id',
        'timezone',
        'site_type',
        'latitude',
        'longitude',
        'lifecycle_status',
        'is_public',
        'published_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'lifecycle_status' => SiteLifecycleStatus::class,
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function operatorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'operator_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function siteHostOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'site_host_organization_id');
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Barangay, $this> */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /** @return HasMany<SiteOperatingHour, $this> */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(SiteOperatingHour::class);
    }

    /** @return BelongsToMany<SiteAmenity, $this> */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(SiteAmenity::class)->withPivot('tenant_id')->withTimestamps();
    }

    /** @return HasMany<SitePhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(SitePhoto::class);
    }

    /** @return HasMany<ParkingRule, $this> */
    public function parkingRules(): HasMany
    {
        return $this->hasMany(ParkingRule::class);
    }

    /** @return HasMany<ChargingStation, $this> */
    public function chargingStations(): HasMany
    {
        return $this->hasMany(ChargingStation::class);
    }
}
