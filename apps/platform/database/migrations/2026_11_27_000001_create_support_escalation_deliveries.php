<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_escalation_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('ticket_id');
            $table->ulid('escalation_id');
            $table->ulid('recipient_public_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'support_escalation_intent_uq');
            $table->index(['tenant_id', 'ticket_id', 'escalation_id'], 'support_escalation_ticket_idx');
            $table->index(['tenant_id', 'sent_at', 'available_at'], 'support_escalation_due_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve delivery intent and acceptance evidence during an application rollback.
    }
};
