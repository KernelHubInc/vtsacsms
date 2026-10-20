<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->enum('review_mode', ['manual', 'automatic'])->default('manual');
            $table->timestampsTz();
        });
        Schema::table('kyc_verifications', function (Blueprint $table): void {
            $table->enum('review_mode', ['manual', 'automatic'])->default('manual');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve verification policy history; roll forward or restore an approved backup.');
    }
};
