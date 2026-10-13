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
            $table->string('reported_status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charging_connector_statuses', function (Blueprint $table): void {
            $table->dropColumn('reported_status');
        });
    }
};
