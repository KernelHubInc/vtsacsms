<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('ticket_number', 80);
            $table->foreignId('requester_user_id')->nullable();
            $table->ulid('site_id')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('priority', 24)->default('normal');
            $table->string('category', 48)->default('general');
            $table->string('subject', 200);
            $table->text('description');
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'ticket_number'], 'support_ticket_number_uq');
            $table->index(['tenant_id', 'status', 'priority', 'created_at'], 'support_ticket_queue_idx');
            $table->index(['tenant_id', 'assigned_to_user_id', 'status'], 'support_ticket_assignee_idx');
            $table->foreign('requester_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['tenant_id', 'site_id'], 'support_ticket_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('support_ticket_id');
            $table->foreignId('author_user_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestampsTz();
            $table->index(['tenant_id', 'support_ticket_id', 'created_at'], 'support_message_timeline_idx');
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['tenant_id', 'support_ticket_id'], 'support_message_ticket_fk')
                ->references(['tenant_id', 'id'])->on('support_tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }

    private function tenantKey(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->ulid('tenant_id');
        $table->unique(['tenant_id', 'id']);
        $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
    }
};
