<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_customer_guards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->unique(['tenant_id', 'subject_id']);
        });
        Schema::create('charging_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->ulid('connector_id');
            $table->ulid('tariff_version_id');
            $table->unsignedBigInteger('budget_minor');
            $table->json('disclosure');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'subject_id', 'id']);
            $table->foreign(['tenant_id', 'connector_id'])->references(['tenant_id', 'id'])->on('connectors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tariff_version_id'])->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
        });
        Schema::create('charging_customer_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subject_id');
            $table->ulid('session_id');
            $table->ulid('quote_id');
            $table->char('idempotency_hash', 64);
            $table->unsignedBigInteger('budget_minor');
            $table->string('payment_state', 32)->default('authorized');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'session_id']);
            $table->unique(['tenant_id', 'quote_id']);
            $table->unique(['tenant_id', 'subject_id', 'idempotency_hash'], 'customer_charge_idempotency');
            $table->index(['tenant_id', 'subject_id', 'id']);
            $table->foreign(['tenant_id', 'session_id'])->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quote_id'])->references(['tenant_id', 'id'])->on('charging_quotes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_customer_sessions');
        Schema::dropIfExists('charging_quotes');
        Schema::dropIfExists('charging_customer_guards');
    }
};
