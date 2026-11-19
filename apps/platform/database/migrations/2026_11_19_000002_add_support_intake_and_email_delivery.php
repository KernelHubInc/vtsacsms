<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('source', 24)->default('legacy');
            $table->ulid('session_id')->nullable();
            $table->text('contact_email')->nullable();
            $table->timestampTz('contact_verified_at')->nullable();
            $table->timestampTz('contact_expires_at')->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->char('open_fingerprint', 64)->nullable();
            $table->unique(['tenant_id', 'open_fingerprint'], 'support_open_intent_uq');
            $table->index(['tenant_id', 'site_id', 'status'], 'support_site_queue_idx');
            $table->index(['tenant_id', 'updated_at', 'id'], 'support_recent_queue_idx');
            $table->index(['tenant_id', 'contact_expires_at'], 'support_contact_expiry_idx');
            $table->foreign(['tenant_id', 'session_id'], 'support_session_fk')->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });
        Schema::create('support_intake_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('ticket_id');
            $table->string('idempotency_key', 160);
            $table->char('request_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'support_intake_idempotency_uq');
            $table->index(['tenant_id', 'ticket_id'], 'support_intake_ticket_idx');
            $table->foreign(['tenant_id', 'ticket_id'])->references(['tenant_id', 'id'])->on('support_tickets')->restrictOnDelete();
        });
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->string('idempotency_key', 160)->nullable();
            $table->unique(['tenant_id', 'support_ticket_id', 'idempotency_key'], 'support_reply_idempotency_uq');
        });
        Schema::create('support_email_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('ticket_id');
            $table->ulid('support_message_id')->nullable();
            $table->string('kind', 24);
            $table->string('idempotency_key', 160);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'support_email_tenant_id_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'support_email_idempotency_uq');
            $table->index(['tenant_id', 'sent_at', 'available_at'], 'support_email_due_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Retain accepted tickets and delivery evidence when rolling back application code.
    }
};
