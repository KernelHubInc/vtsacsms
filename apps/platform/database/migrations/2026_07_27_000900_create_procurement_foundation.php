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
        Schema::create('procurement_departments', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 40);
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'proc_department_code_uq');
        });

        Schema::create('procurement_cost_centers', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 40);
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'proc_cost_center_code_uq');
        });

        Schema::create('procurement_suppliers', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'proc_supplier_code_uq');
            $table->index(['tenant_id', 'status', 'name'], 'proc_supplier_status_idx');
        });

        Schema::create('procurement_approval_rules', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('document_type', 40);
            $table->ulid('department_id')->nullable();
            $table->ulid('cost_center_id')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('minimum_amount_minor')->default(0);
            $table->unsignedBigInteger('maximum_amount_minor')->nullable();
            $table->string('approver_role_key', 120);
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['tenant_id', 'document_type', 'currency', 'is_active'], 'proc_approval_rule_lookup_idx');
        });

        Schema::create('purchase_requests', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('request_number', 80);
            $table->ulid('requested_by');
            $table->ulid('department_id');
            $table->ulid('cost_center_id');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->date('needed_by')->nullable();
            $table->text('business_reason');
            $table->text('decision_reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'request_number'], 'proc_request_number_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'proc_request_status_idx');
            $table->foreign(['tenant_id', 'department_id'], 'proc_request_department_fk')
                ->references(['tenant_id', 'id'])->on('procurement_departments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'], 'proc_request_cost_center_fk')
                ->references(['tenant_id', 'id'])->on('procurement_cost_centers')->restrictOnDelete();
        });

        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_request_id');
            $table->ulid('item_id')->nullable();
            $table->ulid('uom_id')->nullable();
            $table->string('description', 500);
            $table->unsignedBigInteger('quantity_base');
            $table->unsignedBigInteger('estimated_unit_minor');
            $table->unsignedBigInteger('estimated_total_minor');
            $table->timestampsTz();
            $table->index(['tenant_id', 'purchase_request_id'], 'proc_request_line_request_idx');
            $table->foreign(['tenant_id', 'purchase_request_id'], 'proc_request_line_request_fk')
                ->references(['tenant_id', 'id'])->on('purchase_requests')->restrictOnDelete();
        });

        Schema::create('procurement_document_approvals', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('document_type', 40);
            $table->ulid('document_id');
            $table->unsignedInteger('document_revision')->default(1);
            $table->unsignedSmallInteger('sequence');
            $table->string('approver_role_key', 120);
            $table->string('status', 24)->default('pending');
            $table->ulid('decided_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['tenant_id', 'document_type', 'document_id', 'document_revision', 'sequence'],
                'proc_document_approval_sequence_uq',
            );
            $table->index(['tenant_id', 'status', 'created_at'], 'proc_document_approval_queue_idx');
        });

        Schema::create('procurement_rfqs', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_request_id');
            $table->string('rfq_number', 80);
            $table->string('status', 24)->default('draft');
            $table->timestampTz('closes_at');
            $table->text('instructions')->nullable();
            $table->ulid('created_by');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rfq_number'], 'proc_rfq_number_uq');
            $table->index(['tenant_id', 'status', 'closes_at'], 'proc_rfq_status_idx');
            $table->foreign(['tenant_id', 'purchase_request_id'], 'proc_rfq_request_fk')
                ->references(['tenant_id', 'id'])->on('purchase_requests')->restrictOnDelete();
        });

        Schema::create('procurement_rfq_suppliers', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('rfq_id');
            $table->ulid('supplier_id');
            $table->string('status', 24)->default('invited');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rfq_id', 'supplier_id'], 'proc_rfq_supplier_uq');
        });

        Schema::create('supplier_quotations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('rfq_id');
            $table->ulid('supplier_id');
            $table->string('quotation_reference', 120);
            $table->string('status', 24)->default('submitted');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->date('valid_until')->nullable();
            $table->json('commercial_terms')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rfq_id', 'supplier_id', 'quotation_reference'], 'proc_quote_reference_uq');
            $table->index(['tenant_id', 'rfq_id', 'status'], 'proc_quote_rfq_idx');
        });

        Schema::create('supplier_quotation_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('supplier_quotation_id');
            $table->ulid('purchase_request_line_id');
            $table->unsignedBigInteger('quantity_base');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('offered_description', 500)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'supplier_quotation_id'], 'proc_quote_line_quote_idx');
        });

        Schema::create('quotation_comparisons', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('rfq_id');
            $table->ulid('selected_quotation_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->json('comparison_snapshot');
            $table->text('selection_reason')->nullable();
            $table->ulid('prepared_by');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rfq_id'], 'proc_comparison_rfq_uq');
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_request_id');
            $table->ulid('supplier_id');
            $table->ulid('selected_quotation_id')->nullable();
            $table->string('po_number', 80);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->ulid('delivery_warehouse_id')->nullable();
            $table->text('terms')->nullable();
            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'po_number'], 'proc_po_number_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'proc_po_status_idx');
            $table->foreign(['tenant_id', 'purchase_request_id'], 'proc_po_request_fk')
                ->references(['tenant_id', 'id'])->on('purchase_requests')->restrictOnDelete();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_order_id');
            $table->ulid('purchase_request_line_id')->nullable();
            $table->ulid('item_id')->nullable();
            $table->ulid('uom_id')->nullable();
            $table->string('description', 500);
            $table->unsignedBigInteger('ordered_quantity_base');
            $table->unsignedBigInteger('received_quantity_base')->default(0);
            $table->unsignedBigInteger('accepted_quantity_base')->default(0);
            $table->unsignedBigInteger('returned_quantity_base')->default(0);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestampsTz();
            $table->index(['tenant_id', 'purchase_order_id'], 'proc_po_line_po_idx');
            $table->foreign(['tenant_id', 'purchase_order_id'], 'proc_po_line_po_fk')
                ->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        Schema::create('vendor_invoices', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('supplier_id');
            $table->ulid('purchase_order_id');
            $table->string('invoice_reference', 120);
            $table->string('status', 32)->default('submitted');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('accounting_export_state', 24)->default('not_ready');
            $table->timestampTz('matched_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'supplier_id', 'invoice_reference'], 'proc_vendor_invoice_reference_uq');
            $table->index(['tenant_id', 'status', 'invoice_date'], 'proc_vendor_invoice_status_idx');
            $table->foreign(['tenant_id', 'purchase_order_id'], 'proc_vendor_invoice_po_fk')
                ->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        Schema::create('vendor_invoice_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('vendor_invoice_id');
            $table->ulid('purchase_order_line_id');
            $table->unsignedBigInteger('quantity_base');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestampsTz();
            $table->index(['tenant_id', 'vendor_invoice_id'], 'proc_vendor_invoice_line_idx');
        });

        Schema::create('three_way_matches', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('vendor_invoice_id');
            $table->ulid('purchase_order_id');
            $table->string('status', 24);
            $table->unsignedBigInteger('po_total_minor');
            $table->unsignedBigInteger('invoice_total_minor');
            $table->bigInteger('amount_variance_minor');
            $table->unsignedBigInteger('ordered_quantity_base');
            $table->unsignedBigInteger('accepted_quantity_base');
            $table->unsignedBigInteger('invoiced_quantity_base');
            $table->json('evidence_snapshot');
            $table->ulid('matched_by');
            $table->timestampTz('matched_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'vendor_invoice_id', 'matched_at'], 'proc_three_way_invoice_idx');
            $table->index(['tenant_id', 'status', 'matched_at'], 'proc_three_way_status_idx');
        });

        Schema::create('procurement_discrepancies', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('three_way_match_id');
            $table->string('type', 40);
            $table->string('status', 24)->default('open');
            $table->string('severity', 16)->default('warning');
            $table->text('description');
            $table->json('evidence');
            $table->ulid('resolved_by')->nullable();
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at'], 'proc_discrepancy_queue_idx');
        });

        Schema::create('procurement_supplier_returns', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_order_id');
            $table->ulid('supplier_id');
            $table->ulid('inventory_return_id');
            $table->string('return_number', 80);
            $table->string('status', 24)->default('authorized');
            $table->text('reason');
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'return_number'], 'proc_supplier_return_number_uq');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresChecks();
        }
    }

    public function down(): void
    {
        foreach ([
            'procurement_supplier_returns', 'procurement_discrepancies', 'three_way_matches',
            'vendor_invoice_lines', 'vendor_invoices', 'purchase_order_lines', 'purchase_orders',
            'quotation_comparisons', 'supplier_quotation_lines', 'supplier_quotations',
            'procurement_rfq_suppliers', 'procurement_rfqs', 'procurement_document_approvals',
            'purchase_request_lines', 'purchase_requests', 'procurement_approval_rules',
            'procurement_suppliers', 'procurement_cost_centers', 'procurement_departments',
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
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT proc_request_status_chk CHECK (status IN ('draft', 'submitted', 'under_approval', 'approved', 'rejected', 'revision_requested', 'sourcing', 'ordered', 'closed', 'canceled'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT proc_po_status_chk CHECK (status IN ('draft', 'submitted', 'approved', 'issued', 'partially_received', 'received', 'closed', 'canceled'))");
        DB::statement("ALTER TABLE vendor_invoices ADD CONSTRAINT proc_vendor_invoice_status_chk CHECK (status IN ('submitted', 'matching', 'matched', 'discrepancy', 'approved', 'rejected', 'exported'))");
        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT proc_request_line_qty_chk CHECK (quantity_base > 0 AND estimated_total_minor = quantity_base * estimated_unit_minor)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT proc_po_line_qty_chk CHECK (ordered_quantity_base > 0 AND line_total_minor = ordered_quantity_base * unit_price_minor AND accepted_quantity_base <= received_quantity_base AND returned_quantity_base <= accepted_quantity_base)');
        DB::statement('ALTER TABLE procurement_approval_rules ADD CONSTRAINT proc_approval_range_chk CHECK (maximum_amount_minor IS NULL OR maximum_amount_minor >= minimum_amount_minor)');
    }
};
