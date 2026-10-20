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
        Schema::create('wallet_topup_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->bigInteger('minimum_minor');
            $table->bigInteger('maximum_minor');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallet_topup_settings ADD CHECK (minimum_minor > 0 AND maximum_minor >= minimum_minor AND maximum_minor <= 100000000)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve wallet policy history; roll forward or restore an approved backup.');
    }
};
