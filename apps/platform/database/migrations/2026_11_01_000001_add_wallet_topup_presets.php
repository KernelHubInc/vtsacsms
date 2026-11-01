<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topup_settings', function (Blueprint $table): void {
            $table->jsonb('presets')->nullable();
            $table->boolean('allow_custom_amount')->default(true);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain wallet settings during rollback; roll forward instead.');
    }
};
