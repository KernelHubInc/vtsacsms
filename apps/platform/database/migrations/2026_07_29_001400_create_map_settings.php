<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope', 32)->unique();
            $table->string('default_provider', 32)->nullable();
            $table->string('admin_provider', 32)->nullable();
            $table->string('operator_provider', 32)->nullable();
            $table->string('user_web_provider', 32)->nullable();
            $table->string('public_provider', 32)->nullable();
            $table->string('mobile_provider', 32)->nullable();
            $table->decimal('default_latitude', 9, 6)->nullable();
            $table->decimal('default_longitude', 9, 6)->nullable();
            $table->unsignedTinyInteger('default_zoom')->nullable();
            $table->unsignedTinyInteger('minimum_zoom')->nullable();
            $table->unsignedTinyInteger('maximum_zoom')->nullable();
            $table->text('tile_url_template')->nullable();
            $table->string('tile_attribution', 500)->nullable();
            $table->unsignedTinyInteger('tile_maximum_native_zoom')->nullable();
            $table->boolean('clustering_enabled')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_settings');
    }
};
