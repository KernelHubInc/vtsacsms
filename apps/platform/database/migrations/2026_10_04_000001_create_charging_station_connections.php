<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_connector_statuses', function (Blueprint $table): void {
            $table->ulid('ocpp_connection_id')->nullable();
            $table->timestampTz('last_ocpp_event_at', 6)->nullable();
        });
        Schema::create('charging_station_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('charging_station_id');
            $table->ulid('connection_id');
            $table->boolean('connected');
            $table->timestampTz('connected_at', 6);
            $table->timestampTz('last_event_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charging_station_id'], 'station_connection_tenant_unique');
            $table->index(['tenant_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_station_connections');
        Schema::table('charging_connector_statuses', function (Blueprint $table): void {
            $table->dropColumn(['ocpp_connection_id', 'last_ocpp_event_at']);
        });
    }
};
