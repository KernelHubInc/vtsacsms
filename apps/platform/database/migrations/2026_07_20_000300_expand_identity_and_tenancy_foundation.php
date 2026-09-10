<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('activated_at')->nullable()->after('email_verified_at');
            $table->text('mobile_number')->nullable()->after('password');
            $table->char('mobile_number_hash', 64)->nullable()->unique()->after('mobile_number');
            $table->timestampTz('mobile_verified_at')->nullable()->after('mobile_number_hash');
            $table->boolean('mfa_required')->default(false)->after('mobile_verified_at');
            $table->timestampTz('mfa_enrolled_at')->nullable()->after('mfa_required');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('type', 40)->default('charge_point_operator')->after('parent_id');
            $table->index(['tenant_id', 'type', 'is_active'], 'org_tenant_type_active_idx');
        });

        Schema::table('memberships', function (Blueprint $table): void {
            $table->ulid('organization_id')->nullable()->after('user_id');
            $table->index(
                ['tenant_id', 'organization_id', 'status'],
                'membership_tenant_org_status_idx',
            );
            $table->foreign(['tenant_id', 'organization_id'], 'membership_org_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('operator_organization_id');
            $table->ulid('site_host_organization_id')->nullable();
            $table->string('name', 160);
            $table->string('code', 80);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'site_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'site_tenant_code_unique');
            $table->index(['tenant_id', 'operator_organization_id'], 'site_tenant_operator_idx');
            $table->index(['tenant_id', 'site_host_organization_id'], 'site_tenant_host_idx');
            $table->foreign('tenant_id', 'site_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'operator_organization_id'],
                'site_operator_tenant_fk',
            )->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'site_host_organization_id'],
                'site_host_tenant_fk',
            )->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('organization_id')->nullable();
            $table->ulid('role_id');
            $table->string('scope_type', 32);
            $table->ulid('scope_id')->nullable();
            $table->string('email', 254);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'invitation_tenant_id_unique');
            $table->index(
                ['tenant_id', 'email', 'accepted_at', 'revoked_at'],
                'invitation_tenant_email_state_idx',
            );
            $table->foreign('tenant_id', 'invitation_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'], 'invitation_org_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'role_id'], 'invitation_role_tenant_fk')
                ->references(['tenant_id', 'id'])->on('roles')->restrictOnDelete();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->nullableMorphs('tokenable');
            $table->ulid('device_id');
            $table->string('name', 120);
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->unsignedBigInteger('subject_security_version');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'pat_tenant_id_unique');
            $table->index(['tenant_id', 'tokenable_id', 'expires_at'], 'pat_tenant_subject_expiry_idx');
            $table->foreign('tenant_id', 'pat_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_id_hash', 64)->unique();
            $table->string('device_name', 120);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('last_active_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'auth_session_tenant_id_unique');
            $table->index(
                ['tenant_id', 'user_id', 'revoked_at', 'last_active_at'],
                'auth_session_tenant_user_state_idx',
            );
            $table->foreign('tenant_id', 'auth_session_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('mobile_verification_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('mobile_number_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'expires_at', 'consumed_at'], 'mobile_challenge_user_state_idx');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->json('before')->nullable()->after('changes');
            $table->json('after')->nullable()->after('before');
            $table->string('user_agent', 500)->nullable()->after('source_ip');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn(['before', 'after', 'user_agent']);
        });

        Schema::dropIfExists('mobile_verification_challenges');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('sites');

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropForeign('membership_org_tenant_fk');
            $table->dropIndex('membership_tenant_org_status_idx');
            $table->dropColumn('organization_id');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('org_tenant_type_active_idx');
            $table->dropColumn('type');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['mobile_number_hash']);
            $table->dropColumn([
                'activated_at',
                'mobile_number',
                'mobile_number_hash',
                'mobile_verified_at',
                'mfa_required',
                'mfa_enrolled_at',
            ]);
        });
    }
};
