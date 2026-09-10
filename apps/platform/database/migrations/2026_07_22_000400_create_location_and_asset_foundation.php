<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        }

        $this->createGeographyMasters();
        $this->createAssetMasters();
        $this->expandSites();
        $this->createSiteDetails();
        $this->createAssetHierarchy();
        $this->createVehicleCompatibility();
        $this->addStateConstraints();
        $this->addPostgisCoordinate();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sites_coordinates_gist');
            DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS coordinates');
        }

        foreach ([
            'vehicle_connector_compatibilities', 'vehicle_variants', 'vehicle_models',
            'vehicle_manufacturers', 'electricity_meters', 'sim_cards', 'network_providers',
            'asset_components', 'asset_classes', 'connectors', 'charging_current_types',
            'connector_standards', 'evses', 'charging_station_photos',
            'charging_station_firmware_history', 'charging_stations', 'firmware_versions',
            'charger_models', 'charger_manufacturers', 'ocpp_security_profiles',
            'ocpp_versions', 'parking_rules', 'site_photos', 'site_amenity', 'site_amenities',
            'site_operating_hours',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['region_id']);
            $table->dropForeign(['province_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['barangay_id']);
            $table->dropUnique('site_public_slug_unique');
            $table->dropIndex('site_public_search_idx');
            $table->dropIndex('site_admin_area_idx');
            $table->dropColumn([
                'public_slug', 'description', 'address_line_1', 'address_line_2', 'postal_code',
                'country_id', 'region_id', 'province_id', 'city_id', 'barangay_id', 'timezone',
                'latitude', 'longitude', 'lifecycle_status', 'is_public', 'published_at', 'retired_at',
            ]);
        });

        foreach (['barangays', 'cities', 'provinces', 'regions', 'countries'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createGeographyMasters(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('iso_alpha_2', 2)->unique();
            $table->char('iso_alpha_3', 3)->unique();
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['archived_at', 'name'], 'country_archive_name_idx');
        });

        Schema::create('regions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('country_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['country_id', 'code']);
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('region_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['region_id', 'code']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('province_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['province_id', 'code']);
        });

        Schema::create('barangays', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('city_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['city_id', 'code']);
        });
    }

    private function createAssetMasters(): void
    {
        $this->createCodeMaster('ocpp_versions', 24);
        $this->createCodeMaster('ocpp_security_profiles', 40);
        $this->createCodeMaster('connector_standards', 40);
        $this->createCodeMaster('charging_current_types', 16);
        $this->createCodeMaster('asset_classes', 60);
        $this->createCodeMaster('network_providers', 80);

        Schema::create('charger_manufacturers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('code', 80)->unique();
            $table->string('website_url', 500)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('charger_models', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('charger_manufacturer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('default_ocpp_version_id')->nullable()->constrained('ocpp_versions')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 80);
            $table->unsignedBigInteger('rated_power_w')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['charger_manufacturer_id', 'code'], 'charger_model_make_code_unique');
        });

        Schema::create('firmware_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('charger_model_id')->constrained()->restrictOnDelete();
            $table->string('version', 120);
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['charger_model_id', 'version'], 'firmware_model_version_unique');
        });
    }

    private function createCodeMaster(string $name, int $codeLength): void
    {
        Schema::create($name, function (Blueprint $table) use ($codeLength): void {
            $table->ulid('id')->primary();
            $table->string('code', $codeLength)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
    }

    private function expandSites(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('public_slug', 180)->nullable();
            $table->text('description')->nullable();
            $table->string('address_line_1', 180)->nullable();
            $table->string('address_line_2', 180)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->foreignUlid('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('region_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('province_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('city_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('barangay_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->boolean('is_public')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->unique('public_slug', 'site_public_slug_unique');
            $table->index(['lifecycle_status', 'is_public', 'published_at'], 'site_public_search_idx');
            $table->index(['country_id', 'region_id', 'province_id', 'city_id'], 'site_admin_area_idx');
        });
    }

    private function createSiteDetails(): void
    {
        Schema::create('site_operating_hours', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'site_id', 'day_of_week'], 'hours_tenant_site_day_unique');
            $this->tenantSiteForeign($table, 'site_hours');
        });

        Schema::create('site_amenities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('site_amenity', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->ulid('site_id');
            $table->foreignUlid('site_amenity_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->primary(['tenant_id', 'site_id', 'site_amenity_id'], 'site_amenity_primary');
            $this->tenantSiteForeign($table, 'site_amenity');
        });

        Schema::create('site_photos', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id');
            $this->mediaColumns($table);
            $table->timestampsTz();
            $this->tenantSiteForeign($table, 'site_photo');
        });

        Schema::create('parking_rules', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id');
            $table->string('title', 160);
            $table->text('description');
            $table->unsignedInteger('maximum_stay_seconds')->nullable();
            $table->boolean('is_enforced')->default(true);
            $table->timestampsTz();
            $this->tenantSiteForeign($table, 'parking_rule');
        });
    }

    private function createAssetHierarchy(): void
    {
        Schema::create('charging_stations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id');
            $table->foreignUlid('charger_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('ocpp_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('ocpp_security_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('charge_point_identity', 120)->unique();
            $table->string('serial_number', 160);
            $table->string('qr_identifier', 120)->unique();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->boolean('is_public')->default(false);
            $table->timestampTz('commissioned_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'serial_number'], 'station_tenant_serial_unique');
            $table->index(['tenant_id', 'site_id', 'lifecycle_status'], 'station_tenant_site_state_idx');
            $this->tenantSiteForeign($table, 'station');
        });

        Schema::create('charging_station_firmware_history', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charging_station_id');
            $table->foreignUlid('firmware_version_id')->constrained()->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charging_station_id', 'effective_from'], 'station_firmware_effective_unique');
            $table->index(['tenant_id', 'charging_station_id', 'effective_to'], 'station_firmware_current_idx');
            $table->foreign(['tenant_id', 'charging_station_id'], 'station_firmware_station_fk')
                ->references(['tenant_id', 'id'])->on('charging_stations')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX station_firmware_one_current_unique ON charging_station_firmware_history (tenant_id, charging_station_id) WHERE effective_to IS NULL');

        Schema::create('charging_station_photos', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charging_station_id');
            $this->mediaColumns($table);
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'charging_station_id'], 'station_photo_station_fk')
                ->references(['tenant_id', 'id'])->on('charging_stations')->restrictOnDelete();
        });

        Schema::create('evses', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charging_station_id');
            $table->unsignedSmallInteger('evse_number');
            $table->string('uid', 120)->nullable();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charging_station_id', 'evse_number'], 'evse_station_number_unique');
            $table->foreign(['tenant_id', 'charging_station_id'], 'evse_station_fk')
                ->references(['tenant_id', 'id'])->on('charging_stations')->restrictOnDelete();
        });

        Schema::create('connectors', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('evse_id');
            $table->foreignUlid('connector_standard_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('charging_current_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('connector_number');
            $table->string('serial_number', 160)->nullable();
            $table->string('qr_identifier', 120)->unique();
            $table->unsignedBigInteger('maximum_power_w');
            $table->unsignedInteger('maximum_voltage_v')->nullable();
            $table->unsignedInteger('maximum_current_a')->nullable();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'evse_id', 'connector_number'], 'connector_evse_number_unique');
            $table->unique(['tenant_id', 'serial_number'], 'connector_tenant_serial_unique');
            $table->index(['tenant_id', 'lifecycle_status', 'connector_standard_id'], 'connector_tenant_state_standard_idx');
            $table->foreign(['tenant_id', 'evse_id'], 'connector_evse_fk')
                ->references(['tenant_id', 'id'])->on('evses')->restrictOnDelete();
        });

        Schema::create('asset_components', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('connector_id');
            $table->foreignUlid('asset_class_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('serial_number', 160)->nullable();
            $table->string('qr_identifier', 120)->nullable()->unique();
            $table->string('lifecycle_status', 24)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'serial_number'], 'component_tenant_serial_unique');
            $table->foreign(['tenant_id', 'connector_id'], 'component_connector_fk')
                ->references(['tenant_id', 'id'])->on('connectors')->restrictOnDelete();
        });

        Schema::create('sim_cards', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charging_station_id');
            $table->foreignUlid('network_provider_id')->constrained()->restrictOnDelete();
            $table->string('iccid', 32)->unique();
            $table->string('msisdn', 32)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'charging_station_id'], 'sim_station_fk')
                ->references(['tenant_id', 'id'])->on('charging_stations')->restrictOnDelete();
        });

        Schema::create('electricity_meters', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charging_station_id');
            $table->ulid('evse_id')->nullable();
            $table->string('serial_number', 160);
            $table->string('meter_type', 40);
            $table->string('lifecycle_status', 24)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'serial_number'], 'meter_tenant_serial_unique');
            $table->foreign(['tenant_id', 'charging_station_id'], 'meter_station_fk')
                ->references(['tenant_id', 'id'])->on('charging_stations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'evse_id'], 'meter_evse_fk')
                ->references(['tenant_id', 'id'])->on('evses')->restrictOnDelete();
        });
    }

    private function createVehicleCompatibility(): void
    {
        Schema::create('vehicle_manufacturers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('code', 80)->unique();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_manufacturer_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 80);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['vehicle_manufacturer_id', 'code'], 'vehicle_model_make_code_unique');
        });
        Schema::create('vehicle_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 80);
            $table->unsignedBigInteger('battery_capacity_wh')->nullable();
            $table->unsignedBigInteger('maximum_ac_power_w')->nullable();
            $table->unsignedBigInteger('maximum_dc_power_w')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['vehicle_model_id', 'code'], 'vehicle_variant_model_code_unique');
        });
        Schema::create('vehicle_connector_compatibilities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('connector_standard_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('charging_current_type_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('maximum_power_w')->nullable();
            $table->timestampsTz();
            $table->unique(['vehicle_variant_id', 'connector_standard_id', 'charging_current_type_id'], 'vehicle_connector_compat_unique');
        });
    }

    private function addPostgisCoordinate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE sites
            ADD COLUMN coordinates geography(Point, 4326)
            GENERATED ALWAYS AS (
                CASE
                    WHEN latitude IS NULL OR longitude IS NULL THEN NULL
                    ELSE ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
                END
            ) STORED
        SQL);
        DB::statement('CREATE INDEX sites_coordinates_gist ON sites USING GIST (coordinates)');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_latitude_check CHECK (latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_longitude_check CHECK (longitude BETWEEN -180 AND 180)');
    }

    private function addStateConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['sites', 'charging_stations', 'evses', 'connectors', 'asset_components', 'electricity_meters'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_lifecycle_check CHECK (lifecycle_status IN ('draft', 'active', 'maintenance', 'retired'))");
        }
    }

    private function tenantKey(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->ulid('tenant_id');
        $table->unique(['tenant_id', 'id']);
        $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
    }

    private function tenantSiteForeign(Blueprint $table, string $prefix): void
    {
        $table->index(['tenant_id', 'site_id'], $prefix.'_tenant_site_idx');
        $table->foreign(['tenant_id', 'site_id'], $prefix.'_site_fk')
            ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
    }

    private function mediaColumns(Blueprint $table): void
    {
        $table->string('disk', 40)->default('s3');
        $table->string('path', 500);
        $table->string('mime_type', 120);
        $table->unsignedBigInteger('size_bytes');
        $table->string('alt_text', 240);
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->boolean('is_public')->default(false);
    }
};
