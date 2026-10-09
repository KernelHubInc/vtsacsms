<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_charger_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('charging_station_id');
            $table->string('password_hash');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charging_station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_charger_credentials');
    }
};
