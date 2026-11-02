<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_driver_notices', function (Blueprint $table): void {
            $table->boolean('send_push')->default(false);
        });
        Schema::create('notice_push_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('notice_id');
            $table->ulid('device_id');
            $table->char('installation_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'notice_id', 'installation_hash'], 'notice_push_once');
            $table->index(['tenant_id', 'status', 'available_at'], 'notice_push_due');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'notice_id'])->references(['tenant_id', 'id'])
                ->on('cms_driver_notices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_push_deliveries');
        Schema::table('cms_driver_notices', fn (Blueprint $table) => $table->dropColumn('send_push'));
    }
};
