<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('requested_by');
            $table->string('type', 32);
            $table->string('status', 24)->default('queued');
            $table->json('filters');
            $table->string('disk', 32)->nullable();
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'portal_export_requester_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'portal_export_status_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('requested_by')->references('public_id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_exports');
    }
};
