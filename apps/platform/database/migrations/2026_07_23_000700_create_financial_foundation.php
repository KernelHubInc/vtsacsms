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
        Schema::create('payment_provider_configs', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('provider', 40);
            $table->string('environment', 16)->default('local');
            $table->string('account_reference', 160)->nullable();
            $table->string('api_version', 32)->nullable();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->json('capabilities');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider', 'configuration_version'], 'pay_provider_config_version_uq');
            $table->index(['tenant_id', 'is_active', 'provider'], 'pay_provider_active_idx');
        });

        Schema::create('billing_profiles', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('user_id')->nullable();
            $table->string('profile_type', 24)->default('individual');
            $table->string('legal_name', 200);
            $table->string('email', 254);
            $table->string('phone', 32)->nullable();
            $table->text('address_encrypted')->nullable();
            $table->text('tax_identifier_encrypted')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'user_id', 'archived_at'], 'billing_profile_user_idx');
        });

        Schema::create('stored_payment_methods', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('user_id');
            $table->ulid('provider_config_id');
            $table->text('provider_token_encrypted');
            $table->char('provider_token_hash', 64);
            $table->string('type', 32)->default('card');
            $table->string('display_brand', 32)->nullable();
            $table->string('display_last4', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider_config_id', 'provider_token_hash'], 'pay_method_token_hash_uq');
            $table->index(['tenant_id', 'user_id', 'revoked_at'], 'pay_method_user_idx');
            $table->foreign(['tenant_id', 'provider_config_id'], 'pay_method_provider_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configs')->restrictOnDelete();
        });

        Schema::create('payment_intents', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('user_id')->nullable();
            $table->ulid('billing_profile_id')->nullable();
            $table->ulid('provider_config_id');
            $table->ulid('payment_method_id')->nullable();
            $table->string('billable_type', 48);
            $table->ulid('billable_id');
            $table->string('state', 32)->default('created');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_requested_minor');
            $table->unsignedBigInteger('amount_authorized_minor')->default(0);
            $table->unsignedBigInteger('amount_captured_minor')->default(0);
            $table->unsignedBigInteger('amount_refunded_minor')->default(0);
            $table->string('idempotency_key', 160);
            $table->string('provider_intent_reference', 200)->nullable();
            $table->boolean('preauthorization_required')->default(false);
            $table->unsignedInteger('aggregate_version')->default(1);
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'pay_intent_idempotency_uq');
            $table->index(['tenant_id', 'state', 'created_at'], 'pay_intent_state_idx');
            $table->index(['tenant_id', 'billable_type', 'billable_id'], 'pay_intent_billable_idx');
            $table->foreign(['tenant_id', 'provider_config_id'], 'pay_intent_provider_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_method_id'], 'pay_intent_method_fk')
                ->references(['tenant_id', 'id'])->on('stored_payment_methods')->restrictOnDelete();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('payment_intent_id');
            $table->string('operation', 32);
            $table->string('state', 24);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('idempotency_key', 160);
            $table->string('provider_reference', 200)->nullable();
            $table->string('provider_status', 80)->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('safe_message')->nullable();
            $table->json('safe_evidence')->nullable();
            $table->char('evidence_hash', 64);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'pay_attempt_idempotency_uq');
            $table->index(['tenant_id', 'payment_intent_id', 'created_at'], 'pay_attempt_intent_idx');
            $table->foreign(['tenant_id', 'payment_intent_id'], 'pay_attempt_intent_fk')
                ->references(['tenant_id', 'id'])->on('payment_intents')->restrictOnDelete();
        });

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('payment_intent_id');
            $table->string('state', 24)->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('idempotency_key', 160);
            $table->string('provider_reference', 200)->nullable();
            $table->string('reason_code', 80);
            $table->text('reason_notes')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'pay_refund_idempotency_uq');
            $table->index(['tenant_id', 'payment_intent_id', 'state'], 'pay_refund_intent_idx');
            $table->foreign(['tenant_id', 'payment_intent_id'], 'pay_refund_intent_fk')
                ->references(['tenant_id', 'id'])->on('payment_intents')->restrictOnDelete();
        });

        Schema::create('payment_webhook_receipts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('provider_config_id');
            $table->string('provider_event_id', 200);
            $table->string('event_type', 160);
            $table->string('provider_resource_reference', 200)->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->char('body_hash', 64);
            $table->json('normalized_payload');
            $table->string('outcome', 32)->default('received');
            $table->timestampTz('processed_at')->nullable();
            $table->string('ignored_reason', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider_config_id', 'provider_event_id'], 'pay_webhook_event_uq');
            $table->index(['tenant_id', 'outcome', 'created_at'], 'pay_webhook_queue_idx');
            $table->foreign(['tenant_id', 'provider_config_id'], 'pay_webhook_provider_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configs')->restrictOnDelete();
        });

        Schema::create('payment_disputes', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('payment_intent_id');
            $table->string('provider_reference', 200);
            $table->string('state', 24)->default('open');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason_code', 120)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('respond_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider_reference'], 'pay_dispute_provider_uq');
            $table->foreign(['tenant_id', 'payment_intent_id'], 'pay_dispute_intent_fk')
                ->references(['tenant_id', 'id'])->on('payment_intents')->restrictOnDelete();
        });

        Schema::create('fake_payment_provider_resources', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('provider_config_id');
            $table->string('provider_reference', 200);
            $table->string('status', 80);
            $table->char('currency', 3);
            $table->unsignedBigInteger('authorized_minor')->default(0);
            $table->unsignedBigInteger('captured_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('scenario', 80)->default('succeed');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider_config_id', 'provider_reference'], 'fake_pay_resource_uq');
            $table->foreign(['tenant_id', 'provider_config_id'], 'fake_pay_provider_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configs')->restrictOnDelete();
        });

        Schema::create('finance_reviews', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('source_type', 48);
            $table->ulid('source_id');
            $table->string('reason_code', 120);
            $table->string('status', 24)->default('open');
            $table->string('severity', 16)->default('warning');
            $table->json('evidence');
            $table->ulid('opened_by')->nullable();
            $table->timestampTz('opened_at');
            $table->ulid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'severity', 'opened_at'], 'finance_review_queue_idx');
        });

        Schema::create('rated_charges', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('charge_detail_record_id');
            $table->ulid('charging_session_id');
            $table->unsignedInteger('cdr_version');
            $table->char('cdr_snapshot_hash', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->json('source_snapshot');
            $table->timestampTz('finalized_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'charge_detail_record_id'], 'rated_charge_cdr_uq');
            $table->index(['tenant_id', 'charging_session_id'], 'rated_charge_session_idx');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('billing_profile_id');
            $table->string('document_reference', 80);
            $table->string('legal_invoice_number', 120)->nullable();
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->boolean('legal_review_required')->default(true);
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'document_reference'], 'invoice_document_ref_uq');
            $table->index(['tenant_id', 'status', 'issued_at'], 'invoice_status_idx');
            $table->foreign(['tenant_id', 'billing_profile_id'], 'invoice_profile_fk')
                ->references(['tenant_id', 'id'])->on('billing_profiles')->restrictOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('invoice_id');
            $table->ulid('rated_charge_id')->nullable();
            $table->string('description', 240);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->json('tax_snapshot')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'invoice_id'], 'invoice_line_invoice_idx');
            $table->foreign(['tenant_id', 'invoice_id'], 'invoice_line_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rated_charge_id'], 'invoice_line_charge_fk')
                ->references(['tenant_id', 'id'])->on('rated_charges')->restrictOnDelete();
        });

        Schema::create('receipts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('invoice_id');
            $table->ulid('payment_intent_id');
            $table->string('document_reference', 80);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('legal_review_required')->default(true);
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'document_reference'], 'receipt_document_ref_uq');
            $table->foreign(['tenant_id', 'invoice_id'], 'receipt_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('invoice_id');
            $table->string('document_reference', 80);
            $table->string('legal_credit_note_number', 120)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason_code', 80);
            $table->text('reason_notes')->nullable();
            $table->boolean('legal_review_required')->default(true);
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'document_reference'], 'credit_note_document_ref_uq');
            $table->foreign(['tenant_id', 'invoice_id'], 'credit_note_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('payment_intent_id');
            $table->ulid('invoice_id');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->timestampTz('allocated_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'payment_intent_id', 'invoice_id'], 'payment_allocation_uq');
            $table->foreign(['tenant_id', 'invoice_id'], 'payment_allocation_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('type', 24);
            $table->string('owner_type', 32)->nullable();
            $table->ulid('owner_id')->nullable();
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code', 'currency'], 'ledger_account_code_currency_uq');
            $table->index(['tenant_id', 'owner_type', 'owner_id'], 'ledger_account_owner_idx');
        });

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('reference_type', 48);
            $table->ulid('reference_id');
            $table->string('event_type', 120);
            $table->char('currency', 3);
            $table->string('idempotency_key', 160);
            $table->text('description')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->timestampTz('posted_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'ledger_transaction_idempotency_uq');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'ledger_transaction_reference_idx');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('ledger_transaction_id');
            $table->ulid('ledger_account_id');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'ledger_transaction_id'], 'ledger_entry_transaction_idx');
            $table->index(['tenant_id', 'ledger_account_id', 'created_at'], 'ledger_entry_account_idx');
            $table->foreign(['tenant_id', 'ledger_transaction_id'], 'ledger_entry_transaction_fk')
                ->references(['tenant_id', 'id'])->on('ledger_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'ledger_account_id'], 'ledger_entry_account_fk')
                ->references(['tenant_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('revenue_share_rules', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id')->nullable();
            $table->ulid('operator_organization_id');
            $table->ulid('site_host_organization_id')->nullable();
            $table->unsignedSmallInteger('platform_basis_points');
            $table->unsignedSmallInteger('operator_basis_points');
            $table->unsignedSmallInteger('site_host_basis_points')->default(0);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['tenant_id', 'site_id', 'effective_from', 'effective_to'], 'revenue_rule_effective_idx');
        });

        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('provider_config_id');
            $table->string('status', 24)->default('processing');
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'period_end'], 'reconciliation_run_status_idx');
        });

        Schema::create('reconciliation_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('reconciliation_run_id');
            $table->string('provider_reference', 200);
            $table->ulid('payment_intent_id')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('provider_gross_minor');
            $table->unsignedBigInteger('provider_refund_minor')->default(0);
            $table->unsignedBigInteger('provider_fee_minor')->default(0);
            $table->bigInteger('provider_net_minor');
            $table->unsignedBigInteger('platform_captured_minor')->default(0);
            $table->unsignedBigInteger('platform_refunded_minor')->default(0);
            $table->bigInteger('difference_minor')->default(0);
            $table->string('outcome', 24);
            $table->json('safe_evidence')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'reconciliation_run_id', 'provider_reference'], 'reconciliation_line_ref_uq');
            $table->index(['tenant_id', 'outcome', 'created_at'], 'reconciliation_line_outcome_idx');
            $table->foreign(['tenant_id', 'reconciliation_run_id'], 'reconciliation_line_run_fk')
                ->references(['tenant_id', 'id'])->on('reconciliation_runs')->restrictOnDelete();
        });

        Schema::create('settlement_batches', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('provider_config_id')->nullable();
            $table->string('reference', 80);
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->ulid('prepared_by')->nullable();
            $table->timestampTz('prepared_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'reference'], 'settlement_batch_ref_uq');
            $table->index(['tenant_id', 'status', 'period_end'], 'settlement_batch_status_idx');
        });

        Schema::create('settlement_items', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('settlement_batch_id');
            $table->string('beneficiary_type', 32);
            $table->ulid('beneficiary_id');
            $table->string('source_type', 48);
            $table->ulid('source_id');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->bigInteger('adjustment_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->timestampsTz();
            $table->index(['tenant_id', 'settlement_batch_id', 'beneficiary_id'], 'settlement_item_batch_idx');
            $table->foreign(['tenant_id', 'settlement_batch_id'], 'settlement_item_batch_fk')
                ->references(['tenant_id', 'id'])->on('settlement_batches')->restrictOnDelete();
        });

        Schema::create('settlement_adjustments', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('settlement_batch_id');
            $table->string('beneficiary_type', 32);
            $table->ulid('beneficiary_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason_code', 80);
            $table->text('reason_notes');
            $table->ulid('created_by');
            $table->timestampsTz();
            $table->index(['tenant_id', 'settlement_batch_id'], 'settlement_adjustment_batch_idx');
            $table->foreign(['tenant_id', 'settlement_batch_id'], 'settlement_adjustment_batch_fk')
                ->references(['tenant_id', 'id'])->on('settlement_batches')->restrictOnDelete();
        });

        Schema::create('accounting_exports', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('adapter', 40);
            $table->string('status', 24)->default('prepared');
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->unsignedInteger('transaction_count');
            $table->char('content_hash', 64);
            $table->json('safe_metadata');
            $table->timestampTz('exported_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'period_end'], 'accounting_export_status_idx');
        });

        Schema::create('electronic_invoice_submissions', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('invoice_id');
            $table->string('adapter', 40);
            $table->string('status', 24)->default('not_configured');
            $table->string('external_reference', 160)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->text('safe_message')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'invoice_id', 'status'], 'einvoice_submission_invoice_idx');
            $table->foreign(['tenant_id', 'invoice_id'], 'einvoice_submission_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresChecks();
        }
    }

    public function down(): void
    {
        foreach ([
            'electronic_invoice_submissions', 'accounting_exports', 'settlement_adjustments', 'settlement_items',
            'settlement_batches', 'reconciliation_lines', 'reconciliation_runs', 'revenue_share_rules',
            'ledger_entries', 'ledger_transactions', 'ledger_accounts', 'payment_allocations', 'credit_notes',
            'receipts', 'invoice_lines', 'invoices', 'rated_charges', 'finance_reviews',
            'fake_payment_provider_resources', 'payment_disputes', 'payment_webhook_receipts', 'payment_refunds',
            'payment_attempts', 'payment_intents', 'stored_payment_methods', 'billing_profiles',
            'payment_provider_configs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS vtsa_prevent_financial_mutation()');
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
        DB::statement("ALTER TABLE payment_provider_configs ADD CONSTRAINT pay_provider_environment_chk CHECK (environment IN ('local', 'test', 'sandbox'))");
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT pay_intent_state_chk CHECK (state IN ('created', 'requires_payment_method', 'authorization_pending', 'requires_action', 'authorized', 'capture_pending', 'partially_captured', 'captured', 'cancel_pending', 'canceled', 'failed', 'expired', 'refund_pending', 'partially_refunded', 'refunded'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT pay_intent_amounts_chk CHECK (amount_captured_minor <= amount_authorized_minor AND amount_refunded_minor <= amount_captured_minor)');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT pay_attempt_state_chk CHECK (state IN ('prepared', 'submitted', 'succeeded', 'failed', 'outcome_unknown', 'manual_review', 'canceled'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_type_chk CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entry_one_side_chk CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0))');
        DB::statement('ALTER TABLE revenue_share_rules ADD CONSTRAINT revenue_share_total_chk CHECK (platform_basis_points + operator_basis_points + site_host_basis_points = 10000)');
        DB::statement('ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_period_chk CHECK (period_end > period_start)');
        DB::statement('ALTER TABLE settlement_batches ADD CONSTRAINT settlement_period_chk CHECK (period_end > period_start)');
        DB::statement('ALTER TABLE settlement_items ADD CONSTRAINT settlement_net_chk CHECK (net_minor = gross_minor - fee_minor + adjustment_minor AND net_minor >= 0)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION vtsa_prevent_financial_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'posted financial evidence is immutable';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        foreach ([
            'payment_attempts', 'rated_charges', 'invoice_lines', 'receipts', 'credit_notes',
            'payment_allocations', 'ledger_transactions', 'ledger_entries', 'reconciliation_lines',
            'settlement_items', 'settlement_adjustments', 'accounting_exports', 'electronic_invoice_submissions',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION vtsa_prevent_financial_mutation()");
        }
    }
};
