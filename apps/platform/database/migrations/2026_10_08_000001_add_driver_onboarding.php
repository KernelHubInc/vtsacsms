<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->text('birth_date')->nullable();
        });
        Schema::create('driver_garages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'subject_id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
        Schema::create('driver_vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('garage_id');
            $table->string('nickname', 80);
            $table->text('plate_number')->nullable();
            $table->boolean('plate_pending')->default(false);
            $table->string('manufacturer', 80)->default('');
            $table->string('model', 80)->default('');
            $table->string('variant', 80)->nullable();
            $table->json('connector_standards');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'garage_id', 'is_default']);
            $table->foreign(['tenant_id', 'garage_id'])->references(['tenant_id', 'id'])->on('driver_garages')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX driver_vehicle_default_uq ON driver_vehicles (tenant_id, garage_id) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_vehicles');
        Schema::dropIfExists('driver_garages');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'middle_name', 'last_name', 'birth_date']));
    }
};
