<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_onboardings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('organization_id');
            $table->ulid('invitation_id');
            $table->ulid('actor_public_id');
            $table->string('request_hash', 64);
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'organization_id']);
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain onboarding audit references; roll back application code without dropping this table.');
    }
};
