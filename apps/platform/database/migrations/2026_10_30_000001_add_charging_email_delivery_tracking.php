<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_email_deliveries', function (Blueprint $table): void {
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('mailer', 64)->nullable();
            $table->string('message_id', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charging_email_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['last_attempt_at', 'last_error_code', 'mailer', 'message_id']);
        });
    }
};
