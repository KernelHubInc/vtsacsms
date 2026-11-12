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
            $table->unsignedInteger('idle_grace_seconds')->nullable();
        });
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->ulid('idle_connection_id')->nullable();
            $table->timestampTz('idle_started_at')->nullable();
            $table->timestampTz('idle_occupied_at')->nullable();
            $table->timestampTz('idle_ended_at')->nullable();
            $table->unsignedBigInteger('idle_fee_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropColumn(['idle_connection_id', 'idle_started_at', 'idle_occupied_at', 'idle_ended_at', 'idle_fee_minor']);
        });
        Schema::table('tariff_versions', function (Blueprint $table): void {
            $table->dropColumn('idle_grace_seconds');
        });
    }
};
