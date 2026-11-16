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
        Schema::table('charging_budget_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('pay_as_you_go_minimum_minor')->default(10000);
        });
        Schema::table('charging_customer_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('spending_ceiling_minor')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charging_budget_settings ADD CHECK (pay_as_you_go_minimum_minor BETWEEN 1 AND 100000000)');
            DB::statement('ALTER TABLE charging_customer_sessions ADD CHECK (spending_ceiling_minor >= budget_minor)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain authorized pay-as-you-go ceilings on rollback; roll forward.');
    }
};
