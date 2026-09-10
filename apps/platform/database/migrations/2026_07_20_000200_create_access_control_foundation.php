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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 100)->unique();
            $table->string('status', 24);
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id'], 'tenants_status_id_idx');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parent_id')->nullable();
            $table->string('name', 160);
            $table->string('code', 80);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'org_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'org_tenant_code_unique');
            $table->index(['tenant_id', 'parent_id'], 'org_tenant_parent_idx');
            $table->foreign('tenant_id', 'org_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'parent_id'], 'org_parent_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->timestampTz('joined_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'membership_tenant_id_unique');
            $table->unique(['tenant_id', 'user_id'], 'membership_tenant_user_unique');
            $table->index(['tenant_id', 'status', 'user_id'], 'membership_tenant_status_user_idx');
            $table->foreign('tenant_id', 'membership_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('key', 100);
            $table->string('name', 160);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'role_tenant_id_unique');
            $table->unique(['tenant_id', 'key'], 'role_tenant_key_unique');
            $table->foreign('tenant_id', 'role_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->string('context', 40);
            $table->string('description', 255);
            $table->timestampsTz();
            $table->index(['context', 'key'], 'permission_context_key_idx');
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->ulid('role_id');
            $table->string('permission_key', 120);
            $table->timestampTz('created_at');
            $table->primary(['tenant_id', 'role_id', 'permission_key'], 'role_permission_primary');
            $table->index(['tenant_id', 'permission_key'], 'role_permission_tenant_key_idx');
            $table->foreign(['tenant_id', 'role_id'], 'role_permission_role_fk')
                ->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->foreign('permission_key', 'role_permission_permission_fk')
                ->references('key')->on('permissions')->restrictOnDelete();
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('membership_id');
            $table->ulid('role_id');
            $table->string('scope_type', 32);
            $table->ulid('scope_id')->nullable();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'assignment_tenant_id_unique');
            $table->index(
                ['tenant_id', 'membership_id', 'scope_type', 'scope_id'],
                'assignment_tenant_member_scope_idx',
            );
            $table->index(['tenant_id', 'role_id'], 'assignment_tenant_role_idx');
            $table->foreign(['tenant_id', 'membership_id'], 'assignment_membership_fk')
                ->references(['tenant_id', 'id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'], 'assignment_role_fk')
                ->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
        });

        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->unsignedBigInteger('subject_security_version');
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'api_token_tenant_id_unique');
            $table->index(['tenant_id', 'user_id', 'revoked_at'], 'api_token_tenant_user_revoked_idx');
            $table->foreign('tenant_id', 'api_token_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('actor_type', 32);
            $table->ulid('actor_id')->nullable();
            $table->string('action', 160);
            $table->string('target_type', 80);
            $table->ulid('target_id')->nullable();
            $table->string('result', 24);
            $table->string('reason', 500)->nullable();
            $table->json('changes');
            $table->json('metadata');
            $table->string('source_ip', 45)->nullable();
            $table->ulid('correlation_id');
            $table->char('previous_hash', 64)->nullable();
            $table->char('content_hash', 64)->unique();
            $table->index(['tenant_id', 'occurred_at', 'id'], 'audit_tenant_time_id_idx');
            $table->index(['tenant_id', 'action', 'occurred_at'], 'audit_tenant_action_time_idx');
            $table->foreign('tenant_id', 'audit_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->protectAuditEvents();
    }

    public function down(): void
    {
        $this->removeAuditProtection();
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('tenants');
    }

    private function protectAuditEvents(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_audit_event_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_events_reject_update
                    BEFORE UPDATE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();

                CREATE TRIGGER audit_events_reject_delete
                    BEFORE DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_reject_update
                    BEFORE UPDATE ON audit_events
                    BEGIN
                        SELECT RAISE(ABORT, 'audit_events are append-only');
                    END;

                CREATE TRIGGER audit_events_reject_delete
                    BEFORE DELETE ON audit_events
                    BEGIN
                        SELECT RAISE(ABORT, 'audit_events are append-only');
                    END;
                SQL);
        }
    }

    private function removeAuditProtection(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_events_reject_update ON audit_events;
                DROP TRIGGER IF EXISTS audit_events_reject_delete ON audit_events;
                DROP FUNCTION IF EXISTS reject_audit_event_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_events_reject_update;
                DROP TRIGGER IF EXISTS audit_events_reject_delete;
                SQL);
        }
    }
};
