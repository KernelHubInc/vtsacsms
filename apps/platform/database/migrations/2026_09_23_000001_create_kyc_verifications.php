<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->ulid('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->string('status', 24);
            $table->string('document_type', 40);
            $table->string('consent_version', 80);
            $table->timestampTz('consented_at');
            $table->unsignedInteger('service_version')->default(0);
            $table->json('evidence')->nullable();
            $table->json('processing_result')->nullable();
            $table->boolean('evidence_deleted')->default(false);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->text('review_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'subject_id', 'idempotency_key'], 'kyc_idempotency_unique');
            $table->index(['tenant_id', 'subject_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('kyc_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('verification_id');
            $table->ulid('event_id')->nullable();
            $table->string('status', 24);
            $table->string('reason_code', 80)->nullable();
            $table->timestampTz('occurred_at');
            $table->unique(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'verification_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('KYC evidence/audit retention must be approved before deletion. Roll forward.');
    }
};
