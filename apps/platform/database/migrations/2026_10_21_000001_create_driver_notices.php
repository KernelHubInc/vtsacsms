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
        Schema::create('cms_driver_notices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('category', 20);
            $table->string('title', 120);
            $table->string('message', 500);
            $table->string('status', 20)->default('draft');
            $table->boolean('show_on_open')->default(true);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'driver_notice_tenant_id_unique');
            $table->index(['tenant_id', 'status', 'starts_at', 'ends_at'], 'driver_notice_active_idx');
            $table->foreign('tenant_id', 'driver_notice_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cms_driver_notices ADD CONSTRAINT driver_notice_category_check CHECK (category IN ('maintenance', 'announcement', 'promotion'))");
            DB::statement("ALTER TABLE cms_driver_notices ADD CONSTRAINT driver_notice_status_check CHECK (status IN ('draft', 'published'))");
            DB::statement('ALTER TABLE cms_driver_notices ADD CONSTRAINT driver_notice_window_check CHECK (ends_at > starts_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_driver_notices');
    }
};
