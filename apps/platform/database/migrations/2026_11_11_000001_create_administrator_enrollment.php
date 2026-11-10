<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_workforce_enrollments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->string('email_hash', 64);
            $table->string('plan_hash', 64);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('email_sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'subject_id']);
            $table->index(['tenant_id', 'expires_at']);
        });
        Schema::create('organization_administrator_bootstraps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->string('plan_hash', 64);
            $table->ulid('organization_id')->nullable();
            $table->ulid('super_subject_id')->nullable();
            $table->ulid('admin_subject_id')->nullable();
            $table->ulid('super_role_id')->nullable();
            $table->ulid('admin_role_id')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain administrator enrollment and privilege evidence; roll back application code without dropping these tables.');
    }
};
