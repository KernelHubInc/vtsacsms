<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('auth_token_id');
            $table->string('platform', 20);
            $table->char('installation_hash', 64)->unique();
            $table->text('installation_ciphertext');
            $table->timestampTz('last_registered_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'auth_token_id'], 'notification_device_auth_unique');
            $table->index(['tenant_id', 'user_id'], 'notification_device_recipient_idx');
            $table->foreign('tenant_id', 'notification_device_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'auth_token_id'], 'notification_device_token_fk')
                ->references(['tenant_id', 'id'])->on('personal_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');
    }
};
