<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariff_versions', function (Blueprint $table): void {
            $table->string('time_basis', 24)->default('elapsed');
        });
        Schema::create('tariff_version_endings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('tariff_version_id');
            $table->timestampTz('ended_at');
            $table->ulid('actor_id')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'tariff_version_id']);
            $table->foreign(['tenant_id', 'tariff_version_id'])->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
        });
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('energy_flow_seconds')->default(0);
            $table->boolean('energy_flow_evidence_complete')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropColumn(['energy_flow_seconds', 'energy_flow_evidence_complete']);
        });
        Schema::dropIfExists('tariff_version_endings');
        Schema::table('tariff_versions', function (Blueprint $table): void {
            $table->dropColumn('time_basis');
        });
    }
};
