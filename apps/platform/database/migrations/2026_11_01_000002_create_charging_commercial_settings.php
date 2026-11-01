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
        Schema::create('charging_budget_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->unsignedBigInteger('minimum_minor');
            $table->unsignedBigInteger('default_minor');
            $table->unsignedBigInteger('maximum_minor');
            $table->jsonb('presets');
            $table->boolean('allow_custom_amount')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
        Schema::create('platform_fee_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('waiver_threshold_minor');
            $table->unsignedInteger('tax_rate_basis_points')->nullable();
            $table->string('beneficiary', 120)->nullable();
            $table->timestampTz('effective_at');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'effective_at', 'id']);
        });
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->bigInteger('platform_fee_minor')->nullable();
            $table->bigInteger('platform_fee_tax_minor')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charging_budget_settings ADD CHECK (minimum_minor > 0 AND default_minor >= minimum_minor AND maximum_minor >= default_minor AND maximum_minor <= 100000000)');
            DB::statement('ALTER TABLE platform_fee_policies ADD CHECK (amount_minor > 0 AND amount_minor <= 100000000 AND waiver_threshold_minor >= 0 AND waiver_threshold_minor <= 100000000 AND (tax_rate_basis_points IS NULL OR tax_rate_basis_points BETWEEN 0 AND 10000) AND (NOT enabled OR (tax_rate_basis_points IS NOT NULL AND beneficiary IS NOT NULL AND length(trim(beneficiary)) > 0)))');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain charging policy history on rollback; roll forward.');
    }
};
