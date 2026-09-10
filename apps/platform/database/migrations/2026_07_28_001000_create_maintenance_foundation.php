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
        Schema::create('maintenance_priorities', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 32);
            $table->string('name', 80);
            $table->unsignedSmallInteger('rank');
            $table->string('color', 24)->default('gray');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'maint_priority_tenant_code_unique');
            $table->index(['tenant_id', 'is_active', 'rank'], 'maint_priority_tenant_active_idx');
        });

        Schema::create('maintenance_sla_policies', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('name', 120);
            $table->unsignedInteger('version')->default(1);
            $table->ulid('priority_id');
            $table->unsignedBigInteger('acknowledge_seconds');
            $table->unsignedBigInteger('resolve_seconds');
            $table->json('pause_states')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name', 'version'], 'maint_sla_tenant_name_ver_unique');
            $table->index(['tenant_id', 'priority_id', 'is_active'], 'maint_sla_tenant_priority_idx');
            $table->foreign(['tenant_id', 'priority_id'], 'maint_sla_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
        });

        Schema::create('maintenance_skills', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 48);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'maint_skill_tenant_code_unique');
        });

        Schema::create('maintenance_technician_skills', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->foreignId('user_id');
            $table->ulid('skill_id');
            $table->string('proficiency', 24)->default('qualified');
            $table->string('credential_reference', 120)->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'skill_id'], 'maint_tech_skill_unique');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'skill_id'], 'maint_tech_skill_skill_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_skills')->restrictOnDelete();
        });

        Schema::create('maintenance_codes', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('type', 24);
            $table->string('code', 48);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'type', 'code'], 'maint_code_tenant_type_code_unique');
        });

        Schema::create('maintenance_checklist_templates', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('name', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('work_type', 32);
            $table->boolean('requires_independent_verification')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name', 'version'], 'maint_check_template_unique');
        });

        Schema::create('maintenance_checklist_template_items', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('template_id');
            $table->unsignedSmallInteger('sort_order');
            $table->string('item_type', 24);
            $table->string('label', 240);
            $table->text('instructions')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('requires_pass')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'template_id', 'sort_order'], 'maint_check_item_order_unique');
            $table->foreign(['tenant_id', 'template_id'], 'maint_check_item_template_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_checklist_templates')->cascadeOnDelete();
        });

        Schema::create('maintenance_service_requests', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('request_number', 80);
            $table->ulid('site_id');
            $table->string('asset_type', 32)->nullable();
            $table->ulid('asset_id')->nullable();
            $table->ulid('priority_id')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->string('source', 32)->default('operator');
            $table->string('status', 32)->default('submitted');
            $table->string('title', 200);
            $table->text('description');
            $table->timestampTz('submitted_at');
            $table->timestampTz('converted_at')->nullable();
            $table->ulid('converted_incident_id')->nullable();
            $table->ulid('converted_work_order_id')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'request_number'], 'maint_service_req_number_unique');
            $table->index(['tenant_id', 'site_id', 'status'], 'maint_service_req_site_status_idx');
            $table->foreign(['tenant_id', 'site_id'], 'maint_service_req_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['tenant_id', 'priority_id'], 'maint_service_req_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('maintenance_incident_rules', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('name', 120);
            $table->string('fault_code', 120);
            $table->string('asset_type', 32)->default('connector');
            $table->ulid('priority_id');
            $table->ulid('sla_policy_id')->nullable();
            $table->boolean('creates_work_order')->default(true);
            $table->unsignedBigInteger('persistent_after_seconds')->default(0);
            $table->unsignedBigInteger('recovery_after_seconds')->default(0);
            $table->unsignedBigInteger('escalation_after_seconds')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'fault_code', 'asset_type'], 'maint_incident_rule_unique');
            $table->foreign(['tenant_id', 'priority_id'], 'maint_incident_rule_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sla_policy_id'], 'maint_incident_rule_sla_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_sla_policies')->restrictOnDelete();
        });

        Schema::create('maintenance_incidents', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('incident_number', 80);
            $table->string('source', 32);
            $table->string('source_key', 160)->nullable();
            $table->ulid('site_id');
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->ulid('rule_id')->nullable();
            $table->string('fault_code', 120);
            $table->char('fingerprint', 64);
            $table->string('state', 32)->default('open');
            $table->ulid('priority_id');
            $table->ulid('sla_policy_id')->nullable();
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->timestampTz('first_observed_at');
            $table->timestampTz('last_observed_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('recovery_candidate_at')->nullable();
            $table->timestampTz('mitigated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->ulid('acknowledged_by')->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'incident_number'], 'maint_incident_number_unique');
            $table->unique(['tenant_id', 'source', 'source_key'], 'maint_incident_source_unique');
            $table->index(['tenant_id', 'site_id', 'state'], 'maint_incident_site_state_idx');
            $table->index(['tenant_id', 'fingerprint', 'resolved_at'], 'maint_incident_fingerprint_idx');
            $table->foreign(['tenant_id', 'site_id'], 'maint_incident_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['tenant_id', 'priority_id'], 'maint_incident_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sla_policy_id'], 'maint_incident_sla_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_sla_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rule_id'], 'maint_incident_rule_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_incident_rules')->restrictOnDelete();
        });

        Schema::create('maintenance_incident_observations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('incident_id');
            $table->string('source_event_id', 160);
            $table->string('status', 32);
            $table->string('fault_code', 120)->nullable();
            $table->json('evidence')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'source_event_id'], 'maint_incident_obs_event_unique');
            $table->index(['tenant_id', 'incident_id', 'observed_at'], 'maint_incident_obs_time_idx');
            $table->foreign(['tenant_id', 'incident_id'], 'maint_incident_obs_incident_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_incidents')->restrictOnDelete();
        });

        Schema::create('maintenance_warranties', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->ulid('vendor_organization_id')->nullable();
            $table->string('warranty_reference', 120);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 24)->default('active');
            $table->text('coverage_notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'warranty_reference'], 'maint_warranty_ref_unique');
            $table->index(['tenant_id', 'asset_type', 'asset_id', 'status'], 'maint_warranty_asset_idx');
            $table->foreign(['tenant_id', 'vendor_organization_id'], 'maint_warranty_vendor_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('maintenance_preventive_plans', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('plan_number', 80);
            $table->ulid('site_id');
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->string('name', 160);
            $table->string('trigger_type', 32);
            $table->unsignedBigInteger('interval_value');
            $table->timestampTz('next_due_at')->nullable();
            $table->unsignedBigInteger('baseline_runtime_seconds')->default(0);
            $table->unsignedBigInteger('baseline_session_count')->default(0);
            $table->unsignedBigInteger('baseline_energy_wh')->default(0);
            $table->ulid('priority_id');
            $table->ulid('sla_policy_id')->nullable();
            $table->ulid('checklist_template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_generated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'plan_number'], 'maint_pm_plan_number_unique');
            $table->index(['tenant_id', 'is_active', 'next_due_at'], 'maint_pm_plan_due_idx');
            $table->foreign(['tenant_id', 'site_id'], 'maint_pm_plan_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['tenant_id', 'priority_id'], 'maint_pm_plan_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sla_policy_id'], 'maint_pm_plan_sla_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_sla_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'checklist_template_id'], 'maint_pm_plan_check_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_checklist_templates')->restrictOnDelete();
        });

        Schema::create('maintenance_work_orders', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('work_order_number', 80);
            $table->string('work_type', 32);
            $table->ulid('site_id');
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->ulid('service_request_id')->nullable();
            $table->ulid('preventive_plan_id')->nullable();
            $table->ulid('reopened_from_id')->nullable();
            $table->ulid('priority_id');
            $table->ulid('sla_policy_id')->nullable();
            $table->ulid('checklist_template_id')->nullable();
            $table->ulid('warranty_id')->nullable();
            $table->string('state', 40)->default('reported');
            $table->string('title', 200);
            $table->text('description');
            $table->text('scope')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('work_performed')->nullable();
            $table->ulid('failure_code_id')->nullable();
            $table->ulid('root_cause_code_id')->nullable();
            $table->ulid('resolution_code_id')->nullable();
            $table->boolean('safety_critical')->default(false);
            $table->boolean('verification_required')->default(false);
            $table->boolean('asset_restriction_required')->default(false);
            $table->string('hold_reason_code', 80)->nullable();
            $table->text('hold_notes')->nullable();
            $table->timestampTz('hold_review_at')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('scheduled_end_at')->nullable();
            $table->string('schedule_timezone', 64)->default('UTC');
            $table->timestampTz('acknowledge_target_at')->nullable();
            $table->timestampTz('resolve_target_at')->nullable();
            $table->timestampTz('sla_paused_at')->nullable();
            $table->unsignedBigInteger('sla_paused_seconds')->default(0);
            $table->timestampTz('acknowledge_breach_notified_at')->nullable();
            $table->timestampTz('resolve_breach_notified_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('acknowledged_by')->nullable();
            $table->ulid('completed_by')->nullable();
            $table->ulid('verified_by')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->char('currency', 3)->default('PHP');
            $table->unsignedBigInteger('estimated_cost_minor')->default(0);
            $table->unsignedBigInteger('actual_cost_minor')->default(0);
            $table->unsignedInteger('aggregate_version')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_number'], 'maint_work_order_number_unique');
            $table->index(['tenant_id', 'site_id', 'state'], 'maint_work_order_site_state_idx');
            $table->index(['tenant_id', 'asset_type', 'asset_id'], 'maint_work_order_asset_idx');
            $table->index(['tenant_id', 'resolve_target_at', 'state'], 'maint_work_order_sla_idx');
            $table->foreign(['tenant_id', 'site_id'], 'maint_work_order_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['tenant_id', 'priority_id'], 'maint_work_order_priority_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_priorities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sla_policy_id'], 'maint_work_order_sla_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_sla_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'service_request_id'], 'maint_work_order_service_req_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_service_requests')->restrictOnDelete();
            $table->foreign(['tenant_id', 'preventive_plan_id'], 'maint_work_order_pm_plan_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_preventive_plans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reopened_from_id'], 'maint_work_order_reopened_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'checklist_template_id'], 'maint_work_order_check_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_checklist_templates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warranty_id'], 'maint_work_order_warranty_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_warranties')->restrictOnDelete();
        });

        Schema::table('maintenance_service_requests', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'converted_incident_id'], 'maint_service_req_incident_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_incidents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'converted_work_order_id'], 'maint_service_req_work_order_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        Schema::create('maintenance_work_order_incidents', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('incident_id');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_id', 'incident_id'], 'maint_work_incident_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_work_incident_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'incident_id'], 'maint_work_incident_incident_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_incidents')->restrictOnDelete();
        });

        Schema::create('maintenance_work_order_transitions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->string('reason_code', 80);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version');
            $table->ulid('actor_id')->nullable();
            $table->ulid('correlation_id');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_id', 'version'], 'maint_work_transition_ver_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_work_transition_order_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        Schema::create('maintenance_work_order_assignments', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->foreignId('technician_user_id')->nullable();
            $table->ulid('vendor_organization_id')->nullable();
            $table->string('status', 24)->default('assigned');
            $table->ulid('assigned_by');
            $table->timestampTz('assigned_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'technician_user_id', 'status'], 'maint_assignment_tech_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_assignment_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign('technician_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_organization_id'], 'maint_assignment_vendor_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('maintenance_work_order_required_skills', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('skill_id');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_id', 'skill_id'], 'maint_work_skill_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_work_skill_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'skill_id'], 'maint_work_skill_skill_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_skills')->restrictOnDelete();
        });

        Schema::create('maintenance_work_order_checklist_items', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('template_item_id')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->string('item_type', 24);
            $table->string('label', 240);
            $table->text('instructions')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('requires_pass')->default(false);
            $table->string('result', 24)->nullable();
            $table->text('notes')->nullable();
            $table->ulid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_id', 'sort_order'], 'maint_work_check_order_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_work_check_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->cascadeOnDelete();
        });

        Schema::create('maintenance_time_entries', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->foreignId('user_id');
            $table->string('type', 16);
            $table->unsignedBigInteger('duration_seconds');
            $table->unsignedBigInteger('rate_minor_per_hour')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('started_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'work_order_id', 'type'], 'maint_time_work_type_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_time_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('maintenance_attachments', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id')->nullable();
            $table->ulid('incident_id')->nullable();
            $table->string('kind', 32);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('scan_status', 24)->default('pending');
            $table->ulid('uploaded_by');
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'work_order_id', 'kind'], 'maint_attachment_work_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_attachment_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'incident_id'], 'maint_attachment_incident_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_incidents')->restrictOnDelete();
        });

        Schema::create('maintenance_part_requirements', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('item_id');
            $table->ulid('warehouse_id');
            $table->ulid('preferred_bin_id')->nullable();
            $table->unsignedBigInteger('required_quantity_base');
            $table->unsignedBigInteger('reserved_quantity_base')->default(0);
            $table->unsignedBigInteger('issued_quantity_base')->default(0);
            $table->unsignedBigInteger('returned_quantity_base')->default(0);
            $table->ulid('reservation_id')->nullable();
            $table->string('status', 24)->default('required');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'work_order_id', 'item_id', 'warehouse_id'], 'maint_part_req_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_part_req_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'item_id'], 'maint_part_req_item_fk')
                ->references(['tenant_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'], 'maint_part_req_warehouse_fk')
                ->references(['tenant_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'preferred_bin_id'], 'maint_part_req_bin_fk')
                ->references(['tenant_id', 'id'])->on('inventory_bins')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'maint_part_req_reservation_fk')
                ->references(['tenant_id', 'id'])->on('inventory_reservations')->restrictOnDelete();
        });

        Schema::create('maintenance_downtime_periods', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id')->nullable();
            $table->ulid('incident_id')->nullable();
            $table->ulid('site_id');
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->string('reason_code', 80);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'asset_type', 'asset_id', 'started_at'], 'maint_downtime_asset_idx');
            $table->foreign(['tenant_id', 'site_id'], 'maint_downtime_site_fk')
                ->references(['tenant_id', 'id'])->on('sites')->restrictOnDelete();
        });

        Schema::create('maintenance_inspections', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->string('type', 32);
            $table->string('outcome', 24);
            $table->text('notes')->nullable();
            $table->ulid('inspected_by');
            $table->timestampTz('inspected_at');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'work_order_id', 'outcome'], 'maint_inspection_work_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_inspection_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        Schema::create('maintenance_rmas', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('warranty_id')->nullable();
            $table->ulid('vendor_organization_id');
            $table->string('rma_number', 120);
            $table->string('status', 32)->default('requested');
            $table->text('reason');
            $table->unsignedBigInteger('claimed_minor')->default(0);
            $table->unsignedBigInteger('approved_minor')->default(0);
            $table->unsignedBigInteger('recovered_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rma_number'], 'maint_rma_number_unique');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_rma_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'warranty_id'], 'maint_rma_warranty_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_warranties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_organization_id'], 'maint_rma_vendor_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('maintenance_vendor_repairs', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->ulid('rma_id')->nullable();
            $table->ulid('vendor_organization_id');
            $table->string('status', 32)->default('awaiting_dispatch');
            $table->string('vendor_reference', 120)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('repair_findings')->nullable();
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampsTz();
            $table->index(['tenant_id', 'work_order_id', 'status'], 'maint_vendor_repair_work_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_vendor_repair_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rma_id'], 'maint_vendor_repair_rma_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_rmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_organization_id'], 'maint_vendor_repair_vendor_fk')
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('maintenance_asset_actions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('work_order_id');
            $table->string('action', 32);
            $table->string('asset_type', 32);
            $table->ulid('asset_id');
            $table->string('status', 24);
            $table->string('reason_code', 80);
            $table->json('evidence')->nullable();
            $table->ulid('requested_by');
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'work_order_id', 'action'], 'maint_asset_action_work_idx');
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_asset_action_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        Schema::create('maintenance_preventive_occurrences', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('plan_id');
            $table->ulid('work_order_id');
            $table->string('checkpoint_key', 160);
            $table->string('trigger_type', 32);
            $table->unsignedBigInteger('trigger_value')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'plan_id', 'checkpoint_key'], 'maint_pm_occurrence_unique');
            $table->foreign(['tenant_id', 'plan_id'], 'maint_pm_occurrence_plan_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_preventive_plans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'work_order_id'], 'maint_pm_occurrence_work_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresControls();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS vtsa_prevent_maintenance_evidence_mutation()');
        }

        Schema::table('maintenance_service_requests', function (Blueprint $table): void {
            $table->dropForeign('maint_service_req_incident_fk');
            $table->dropForeign('maint_service_req_work_order_fk');
        });

        foreach ([
            'maintenance_preventive_occurrences',
            'maintenance_asset_actions',
            'maintenance_vendor_repairs',
            'maintenance_rmas',
            'maintenance_inspections',
            'maintenance_downtime_periods',
            'maintenance_part_requirements',
            'maintenance_attachments',
            'maintenance_time_entries',
            'maintenance_work_order_checklist_items',
            'maintenance_work_order_required_skills',
            'maintenance_work_order_assignments',
            'maintenance_work_order_transitions',
            'maintenance_work_order_incidents',
            'maintenance_work_orders',
            'maintenance_preventive_plans',
            'maintenance_warranties',
            'maintenance_incident_observations',
            'maintenance_incidents',
            'maintenance_incident_rules',
            'maintenance_service_requests',
            'maintenance_checklist_template_items',
            'maintenance_checklist_templates',
            'maintenance_codes',
            'maintenance_technician_skills',
            'maintenance_skills',
            'maintenance_sla_policies',
            'maintenance_priorities',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function tenantKey(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->ulid('tenant_id');
        $table->unique(['tenant_id', 'id']);
        $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
    }

    private function addPostgresControls(): void
    {
        DB::statement('ALTER TABLE maintenance_work_orders ADD CONSTRAINT maint_work_cost_chk CHECK (actual_cost_minor >= 0 AND estimated_cost_minor >= 0)');
        DB::statement('ALTER TABLE maintenance_time_entries ADD CONSTRAINT maint_time_cost_chk CHECK (duration_seconds > 0 AND total_minor = ROUND(duration_seconds * rate_minor_per_hour::numeric / 3600))');
        DB::statement('ALTER TABLE maintenance_part_requirements ADD CONSTRAINT maint_part_qty_chk CHECK (required_quantity_base > 0 AND reserved_quantity_base <= required_quantity_base AND issued_quantity_base <= reserved_quantity_base AND returned_quantity_base <= issued_quantity_base)');
        DB::statement('ALTER TABLE maintenance_downtime_periods ADD CONSTRAINT maint_downtime_time_chk CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement("CREATE UNIQUE INDEX maint_incident_active_fingerprint_unique ON maintenance_incidents (tenant_id, fingerprint) WHERE resolved_at IS NULL AND state IN ('open', 'acknowledged', 'mitigated', 'recurrent')");
        DB::statement(<<<'SQL'
            CREATE FUNCTION vtsa_prevent_maintenance_evidence_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'maintenance transition and observation evidence is immutable';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER maintenance_transitions_immutable BEFORE UPDATE OR DELETE ON maintenance_work_order_transitions FOR EACH ROW EXECUTE FUNCTION vtsa_prevent_maintenance_evidence_mutation()');
        DB::statement('CREATE TRIGGER maintenance_observations_immutable BEFORE UPDATE OR DELETE ON maintenance_incident_observations FOR EACH ROW EXECUTE FUNCTION vtsa_prevent_maintenance_evidence_mutation()');
    }
};
