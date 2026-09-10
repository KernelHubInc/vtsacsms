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
        Schema::create('tariffs', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->string('status', 24)->default('draft');
            $table->ulid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'archived_at'], 'tariff_tenant_status_idx');
        });

        Schema::create('tariff_versions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('tariff_id');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('tax_treatment', 24);
            $table->unsignedInteger('tax_rate_basis_points')->nullable();
            $table->unsignedBigInteger('minimum_fee_minor')->nullable();
            $table->unsignedBigInteger('maximum_fee_minor')->nullable();
            $table->ulid('operator_id')->nullable();
            $table->ulid('site_id')->nullable();
            $table->ulid('connector_id')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestampTz('published_at')->nullable();
            $table->ulid('published_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'tariff_id', 'version'], 'tariff_version_number_unique');
            $table->index(['tenant_id', 'status', 'effective_from', 'effective_to'], 'tariff_version_effective_idx');
            $table->index(['tenant_id', 'connector_id', 'site_id', 'operator_id'], 'tariff_version_scope_idx');
            $table->foreign(['tenant_id', 'tariff_id'], 'tariff_version_tariff_fk')
                ->references(['tenant_id', 'id'])->on('tariffs')->restrictOnDelete();
        });

        Schema::create('tariff_components', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('tariff_version_id');
            $table->string('dimension', 24);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('unit_quantity');
            $table->unsignedSmallInteger('day_of_week_mask')->default(127);
            $table->time('starts_at_local')->nullable();
            $table->time('ends_at_local')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'tariff_version_id', 'dimension', 'priority'], 'tariff_component_lookup_idx');
            $table->foreign(['tenant_id', 'tariff_version_id'], 'tariff_component_version_fk')
                ->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
        });

        Schema::create('tariff_discounts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('tariff_version_id');
            $table->string('name', 160);
            $table->string('code', 80)->nullable();
            $table->string('kind', 24);
            $table->unsignedBigInteger('value');
            $table->boolean('is_automatic')->default(false);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'tariff_version_id', 'code'], 'tariff_discount_lookup_idx');
            $table->foreign(['tenant_id', 'tariff_version_id'], 'tariff_discount_version_fk')
                ->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
        });

        Schema::create('charging_authorization_tokens', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('session_id')->nullable();
            $table->char('token_hash', 64);
            $table->string('token_hint', 12);
            $table->string('status', 24)->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'token_hash'], 'charging_auth_token_hash_unique');
            $table->index(['tenant_id', 'status', 'expires_at'], 'charging_auth_token_active_idx');
        });

        Schema::create('charging_sessions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id');
            $table->ulid('operator_id')->nullable();
            $table->ulid('charging_station_id');
            $table->ulid('evse_id');
            $table->ulid('connector_id');
            $table->unsignedBigInteger('connector_maximum_power_w');
            $table->string('charge_point_identity', 120);
            $table->string('protocol', 24);
            $table->string('protocol_transaction_id', 160)->nullable();
            $table->string('origin', 32);
            $table->string('state', 32);
            $table->string('authorization_status', 24)->default('pending');
            $table->ulid('authorization_token_id')->nullable();
            $table->ulid('tariff_version_id')->nullable();
            $table->json('tariff_snapshot');
            $table->char('tariff_snapshot_hash', 64);
            $table->char('currency', 3)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('start_deadline_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampTz('last_protocol_event_at')->nullable();
            $table->unsignedBigInteger('meter_start_wh')->nullable();
            $table->unsignedBigInteger('meter_stop_wh')->nullable();
            $table->unsignedBigInteger('energy_wh')->default(0);
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('parking_seconds')->default(0);
            $table->unsignedBigInteger('idle_seconds')->default(0);
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();
            $table->unsignedBigInteger('final_cost_minor')->nullable();
            $table->string('finalization_outcome', 24)->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->string('cancellation_reason', 120)->nullable();
            $table->json('anomaly_flags');
            $table->unsignedInteger('aggregate_version')->default(1);
            $table->timestampsTz();
            $table->unique(
                ['tenant_id', 'charging_station_id', 'protocol', 'protocol_transaction_id'],
                'charging_session_protocol_tx_unique',
            );
            $table->index(['tenant_id', 'state', 'requested_at'], 'charging_session_tenant_state_idx');
            $table->index(['tenant_id', 'connector_id', 'state'], 'charging_session_connector_state_idx');
            $table->index(['tenant_id', 'site_id', 'started_at'], 'charging_session_site_time_idx');
            $table->index(['tenant_id', 'charging_station_id', 'last_protocol_event_at'], 'charging_session_station_event_idx');
        });

        Schema::table('charging_authorization_tokens', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'session_id'], 'charging_auth_token_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charging_connector_reservations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('connector_id');
            $table->ulid('session_id');
            $table->timestampTz('expires_at');
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 80)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'connector_id', 'expires_at'], 'connector_reservation_lookup_idx');
            $table->foreign(['tenant_id', 'session_id'], 'connector_reservation_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charging_commands', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('session_id');
            $table->ulid('charging_station_id');
            $table->ulid('connector_id');
            $table->string('charge_point_identity', 120);
            $table->string('type', 32);
            $table->string('ocpp_action', 80);
            $table->string('state', 32);
            $table->string('idempotency_key', 160);
            $table->ulid('correlation_id');
            $table->ulid('actor_id');
            $table->string('reason_code', 120);
            $table->json('payload');
            $table->text('secret_payload')->nullable();
            $table->json('expected_state');
            $table->timestampTz('expires_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'charging_command_idempotency_unique');
            $table->index(['tenant_id', 'state', 'expires_at'], 'charging_command_state_expiry_idx');
            $table->index(['tenant_id', 'session_id', 'created_at'], 'charging_command_session_idx');
            $table->foreign(['tenant_id', 'session_id'], 'charging_command_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charging_meter_readings', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('session_id');
            $table->ulid('event_id');
            $table->unsignedSmallInteger('sample_index');
            $table->timestampTz('sampled_at');
            $table->timestampTz('received_at');
            $table->string('measurand', 100);
            $table->string('unit', 16);
            $table->unsignedBigInteger('value');
            $table->string('context', 80)->nullable();
            $table->string('phase', 40)->nullable();
            $table->boolean('is_out_of_order')->default(false);
            $table->boolean('is_meter_reset')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'event_id', 'sample_index'], 'charging_meter_event_sample_unique');
            $table->index(['tenant_id', 'session_id', 'sampled_at'], 'charging_meter_session_time_idx');
            $table->foreign(['tenant_id', 'session_id'], 'charging_meter_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charging_inbound_events', function (Blueprint $table): void {
            $table->ulid('event_id')->primary();
            $table->ulid('tenant_id');
            $table->string('event_type', 160);
            $table->unsignedSmallInteger('schema_version');
            $table->ulid('aggregate_id');
            $table->ulid('correlation_id');
            $table->ulid('causation_id')->nullable();
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('outcome', 32)->default('received');
            $table->string('error_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'event_id'], 'charging_inbound_tenant_event_unique');
            $table->index(['tenant_id', 'outcome', 'received_at'], 'charging_inbound_outcome_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('charging_session_reviews', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('session_id');
            $table->string('status', 24)->default('open');
            $table->string('reason_code', 120);
            $table->text('notes')->nullable();
            $table->ulid('opened_by')->nullable();
            $table->timestampTz('opened_at');
            $table->ulid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('decision', 40)->nullable();
            $table->json('adjustments')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'opened_at'], 'charging_review_queue_idx');
            $table->foreign(['tenant_id', 'session_id'], 'charging_review_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charge_detail_records', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('session_id');
            $table->unsignedInteger('version');
            $table->string('state', 24);
            $table->json('snapshot')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('energy_wh')->default(0);
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->nullable();
            $table->unsignedBigInteger('discount_minor')->nullable();
            $table->unsignedBigInteger('tax_minor')->nullable();
            $table->unsignedBigInteger('total_minor')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'session_id', 'version'], 'cdr_session_version_unique');
            $table->index(['tenant_id', 'state', 'created_at'], 'cdr_state_queue_idx');
            $table->foreign(['tenant_id', 'session_id'], 'cdr_session_fk')
                ->references(['tenant_id', 'id'])->on('charging_sessions')->restrictOnDelete();
        });

        Schema::create('charging_state_transitions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('aggregate_type', 24);
            $table->ulid('aggregate_id');
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('reason', 160);
            $table->ulid('event_id')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->json('evidence');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id', 'occurred_at'], 'charging_transition_aggregate_idx');
        });

        Schema::create('integration_outbox_events', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('event_id')->unique();
            $table->string('event_type', 160);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('aggregate_type', 80);
            $table->ulid('aggregate_id');
            $table->ulid('correlation_id');
            $table->ulid('causation_id')->nullable();
            $table->json('data');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'published_at', 'occurred_at'], 'outbox_publish_queue_idx');
        });

        DB::statement('CREATE UNIQUE INDEX connector_one_open_reservation_unique ON charging_connector_reservations (tenant_id, connector_id) WHERE released_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX connector_one_physical_session_unique ON charging_sessions (tenant_id, connector_id) WHERE state IN ('charging', 'suspended_by_ev', 'suspended_by_evse', 'stopping', 'finalizing')");

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresChecks();
        }
    }

    public function down(): void
    {
        foreach ([
            'integration_outbox_events',
            'charging_state_transitions',
            'charge_detail_records',
            'charging_session_reviews',
            'charging_inbound_events',
            'charging_meter_readings',
            'charging_commands',
            'charging_connector_reservations',
            'charging_authorization_tokens',
            'charging_sessions',
            'tariff_discounts',
            'tariff_components',
            'tariff_versions',
            'tariffs',
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

    private function addPostgresChecks(): void
    {
        DB::statement("ALTER TABLE tariffs ADD CONSTRAINT tariffs_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_tax_check CHECK (tax_treatment IN ('inclusive', 'exclusive'))");
        DB::statement('ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_tax_rate_check CHECK (tax_rate_basis_points IS NULL OR tax_rate_basis_points <= 10000)');
        DB::statement('ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_fee_bounds_check CHECK (maximum_fee_minor IS NULL OR minimum_fee_minor IS NULL OR maximum_fee_minor >= minimum_fee_minor)');
        DB::statement("ALTER TABLE tariff_components ADD CONSTRAINT tariff_components_dimension_check CHECK (dimension IN ('energy', 'time', 'session', 'parking', 'idle'))");
        DB::statement('ALTER TABLE tariff_components ADD CONSTRAINT tariff_components_unit_check CHECK (unit_quantity > 0)');
        DB::statement('ALTER TABLE tariff_components ADD CONSTRAINT tariff_components_days_check CHECK (day_of_week_mask BETWEEN 1 AND 127)');
        DB::statement("ALTER TABLE tariff_discounts ADD CONSTRAINT tariff_discounts_kind_check CHECK (kind IN ('percentage', 'fixed'))");
        DB::statement("ALTER TABLE charging_sessions ADD CONSTRAINT charging_sessions_protocol_check CHECK (protocol IN ('ocpp1.6', 'ocpp2.0.1'))");
        DB::statement("ALTER TABLE charging_sessions ADD CONSTRAINT charging_sessions_state_check CHECK (state IN ('requested', 'authorizing', 'authorized', 'starting', 'charging', 'suspended_by_ev', 'suspended_by_evse', 'stopping', 'finalizing', 'review_required', 'completed', 'failed', 'cancelled', 'expired'))");
        DB::statement("ALTER TABLE charging_commands ADD CONSTRAINT charging_commands_state_check CHECK (state IN ('requested', 'dispatched', 'acknowledged', 'rejected', 'timed_out', 'delivery_unknown', 'expired'))");
        DB::statement("ALTER TABLE charge_detail_records ADD CONSTRAINT cdr_state_check CHECK (state IN ('pending', 'generating', 'review_required', 'finalized', 'failed', 'superseded'))");
    }
};
