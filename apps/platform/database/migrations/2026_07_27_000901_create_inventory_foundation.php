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
        Schema::create('inventory_units_of_measure', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('code', 24);
            $table->string('name', 80);
            $table->string('dimension', 32)->default('count');
            $table->unsignedBigInteger('base_multiplier')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'inv_uom_code_uq');
        });

        Schema::create('inventory_item_categories', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('parent_id')->nullable();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'inv_category_code_uq');
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('category_id');
            $table->ulid('base_uom_id');
            $table->string('sku', 80);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('tracking_type', 16)->default('none');
            $table->string('valuation_method', 24)->default('moving_average');
            $table->char('currency', 3);
            $table->unsignedBigInteger('standard_cost_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'sku'], 'inv_item_sku_uq');
            $table->index(['tenant_id', 'category_id', 'is_active'], 'inv_item_category_idx');
            $table->foreign(['tenant_id', 'category_id'], 'inv_item_category_fk')
                ->references(['tenant_id', 'id'])->on('inventory_item_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'base_uom_id'], 'inv_item_uom_fk')
                ->references(['tenant_id', 'id'])->on('inventory_units_of_measure')->restrictOnDelete();
        });

        Schema::create('inventory_warehouses', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('site_id')->nullable();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('type', 24)->default('warehouse');
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code'], 'inv_warehouse_code_uq');
            $table->index(['tenant_id', 'site_id', 'is_active'], 'inv_warehouse_site_idx');
        });

        Schema::create('inventory_stock_locations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('warehouse_id');
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('custody_type', 24)->default('available');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'warehouse_id', 'code'], 'inv_location_code_uq');
            $table->index(['tenant_id', 'warehouse_id', 'custody_type'], 'inv_location_custody_idx');
            $table->foreign(['tenant_id', 'warehouse_id'], 'inv_location_warehouse_fk')
                ->references(['tenant_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
        });

        Schema::create('inventory_bins', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('warehouse_id');
            $table->ulid('stock_location_id');
            $table->string('code', 60);
            $table->string('name', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'warehouse_id', 'code'], 'inv_bin_code_uq');
            $table->index(['tenant_id', 'stock_location_id', 'is_active'], 'inv_bin_location_idx');
            $table->foreign(['tenant_id', 'warehouse_id'], 'inv_bin_warehouse_fk')
                ->references(['tenant_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'stock_location_id'], 'inv_bin_location_fk')
                ->references(['tenant_id', 'id'])->on('inventory_stock_locations')->restrictOnDelete();
        });

        Schema::create('inventory_lots', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('item_id');
            $table->string('lot_number', 120);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 24)->default('available');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'item_id', 'lot_number'], 'inv_lot_number_uq');
        });

        Schema::create('inventory_serials', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('item_id');
            $table->string('serial_number', 160);
            $table->string('status', 24)->default('received');
            $table->ulid('current_bin_id')->nullable();
            $table->ulid('asset_id')->nullable();
            $table->timestampTz('handed_to_assets_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'item_id', 'serial_number'], 'inv_serial_number_uq');
            $table->index(['tenant_id', 'status', 'current_bin_id'], 'inv_serial_custody_idx');
        });

        Schema::create('inventory_stock_movements', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('item_id');
            $table->ulid('uom_id');
            $table->ulid('from_bin_id')->nullable();
            $table->ulid('to_bin_id')->nullable();
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->string('movement_type', 40);
            $table->unsignedBigInteger('quantity_base');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('total_cost_minor');
            $table->string('reference_type', 48);
            $table->ulid('reference_id');
            $table->string('reason_code', 80)->nullable();
            $table->string('idempotency_key', 160);
            $table->ulid('reverses_movement_id')->nullable();
            $table->ulid('posted_by');
            $table->timestampTz('occurred_at');
            $table->timestampTz('posted_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'inv_movement_idempotency_uq');
            $table->index(['tenant_id', 'item_id', 'from_bin_id', 'occurred_at'], 'inv_movement_from_idx');
            $table->index(['tenant_id', 'item_id', 'to_bin_id', 'occurred_at'], 'inv_movement_to_idx');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'inv_movement_reference_idx');
            $table->foreign(['tenant_id', 'item_id'], 'inv_movement_item_fk')
                ->references(['tenant_id', 'id'])->on('inventory_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'uom_id'], 'inv_movement_uom_fk')
                ->references(['tenant_id', 'id'])->on('inventory_units_of_measure')->restrictOnDelete();
        });

        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('item_id');
            $table->ulid('warehouse_id');
            $table->ulid('bin_id')->nullable();
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->string('status', 32)->default('requested');
            $table->unsignedBigInteger('requested_quantity_base');
            $table->unsignedBigInteger('reserved_quantity_base')->default(0);
            $table->unsignedBigInteger('consumed_quantity_base')->default(0);
            $table->string('purpose_type', 48);
            $table->ulid('purpose_id');
            $table->string('idempotency_key', 160);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'inv_reservation_idempotency_uq');
            $table->index(['tenant_id', 'item_id', 'warehouse_id', 'status'], 'inv_reservation_availability_idx');
        });

        Schema::create('inventory_goods_receipts', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('purchase_order_id');
            $table->ulid('supplier_id');
            $table->ulid('warehouse_id');
            $table->string('receipt_number', 80);
            $table->string('status', 32)->default('draft');
            $table->string('supplier_delivery_reference', 120)->nullable();
            $table->ulid('received_by');
            $table->timestampTz('received_at');
            $table->ulid('inspected_by')->nullable();
            $table->timestampTz('inspected_at')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'receipt_number'], 'inv_goods_receipt_number_uq');
            $table->index(['tenant_id', 'purchase_order_id', 'status'], 'inv_goods_receipt_po_idx');
            $table->foreign(['tenant_id', 'warehouse_id'], 'inv_goods_receipt_warehouse_fk')
                ->references(['tenant_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
        });

        Schema::create('inventory_goods_receipt_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('goods_receipt_id');
            $table->ulid('purchase_order_line_id');
            $table->ulid('item_id');
            $table->ulid('uom_id');
            $table->ulid('destination_bin_id');
            $table->ulid('rejection_bin_id')->nullable();
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->unsignedBigInteger('received_quantity_base');
            $table->unsignedBigInteger('accepted_quantity_base')->default(0);
            $table->unsignedBigInteger('rejected_quantity_base')->default(0);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->char('currency', 3);
            $table->string('inspection_status', 24)->default('pending');
            $table->text('inspection_notes')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'goods_receipt_id'], 'inv_goods_receipt_line_receipt_idx');
            $table->foreign(['tenant_id', 'goods_receipt_id'], 'inv_goods_receipt_line_receipt_fk')
                ->references(['tenant_id', 'id'])->on('inventory_goods_receipts')->restrictOnDelete();
        });

        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('transfer_number', 80);
            $table->ulid('source_warehouse_id');
            $table->ulid('destination_warehouse_id');
            $table->string('status', 32)->default('draft');
            $table->ulid('requested_by');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->ulid('dispatched_by')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->ulid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('discrepancy_notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'transfer_number'], 'inv_transfer_number_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'inv_transfer_status_idx');
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('transfer_id');
            $table->ulid('item_id');
            $table->ulid('uom_id');
            $table->ulid('source_bin_id');
            $table->ulid('in_transit_bin_id');
            $table->ulid('destination_bin_id');
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->unsignedBigInteger('requested_quantity_base');
            $table->unsignedBigInteger('dispatched_quantity_base')->default(0);
            $table->unsignedBigInteger('received_quantity_base')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'transfer_id'], 'inv_transfer_line_transfer_idx');
            $table->foreign(['tenant_id', 'transfer_id'], 'inv_transfer_line_transfer_fk')
                ->references(['tenant_id', 'id'])->on('inventory_transfers')->restrictOnDelete();
        });

        Schema::create('inventory_returns', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('return_number', 80);
            $table->string('return_type', 32);
            $table->ulid('warehouse_id');
            $table->string('status', 24)->default('draft');
            $table->string('source_type', 48);
            $table->ulid('source_id');
            $table->text('reason');
            $table->ulid('created_by');
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'return_number'], 'inv_return_number_uq');
        });

        Schema::create('inventory_return_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('inventory_return_id');
            $table->ulid('item_id');
            $table->ulid('uom_id');
            $table->ulid('bin_id');
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->unsignedBigInteger('quantity_base');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->char('currency', 3);
            $table->timestampsTz();
            $table->index(['tenant_id', 'inventory_return_id'], 'inv_return_line_return_idx');
        });

        Schema::create('inventory_reorder_points', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('item_id');
            $table->ulid('warehouse_id');
            $table->unsignedBigInteger('reorder_quantity_base');
            $table->unsignedBigInteger('minimum_quantity_base');
            $table->unsignedBigInteger('target_quantity_base');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'item_id', 'warehouse_id'], 'inv_reorder_item_warehouse_uq');
            $table->index(['tenant_id', 'warehouse_id', 'is_active'], 'inv_reorder_warehouse_idx');
        });

        Schema::create('inventory_count_plans', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('plan_number', 80);
            $table->string('count_type', 24)->default('physical');
            $table->ulid('warehouse_id');
            $table->string('status', 32)->default('draft');
            $table->boolean('blind_count')->default(true);
            $table->date('scheduled_for');
            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'plan_number'], 'inv_count_plan_number_uq');
            $table->index(['tenant_id', 'status', 'scheduled_for'], 'inv_count_plan_status_idx');
        });

        Schema::create('inventory_count_sheets', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('count_plan_id');
            $table->ulid('stock_location_id');
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('status', 24)->default('open');
            $table->ulid('assigned_to')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'count_plan_id', 'stock_location_id', 'round'], 'inv_count_sheet_round_uq');
        });

        Schema::create('inventory_count_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('count_sheet_id');
            $table->ulid('item_id');
            $table->ulid('bin_id');
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->bigInteger('system_quantity_base')->nullable();
            $table->unsignedBigInteger('counted_quantity_base')->nullable();
            $table->bigInteger('variance_quantity_base')->nullable();
            $table->ulid('counted_by')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'count_sheet_id'], 'inv_count_line_sheet_idx');
        });

        Schema::create('inventory_adjustment_requests', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->string('adjustment_number', 80);
            $table->string('status', 24)->default('draft');
            $table->ulid('warehouse_id');
            $table->string('reason_code', 80);
            $table->text('reason_notes');
            $table->string('source_type', 48)->nullable();
            $table->ulid('source_id')->nullable();
            $table->ulid('requested_by');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'adjustment_number'], 'inv_adjustment_number_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'inv_adjustment_status_idx');
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table): void {
            $this->tenantKey($table);
            $table->ulid('adjustment_request_id');
            $table->ulid('item_id');
            $table->ulid('uom_id');
            $table->ulid('bin_id');
            $table->ulid('lot_id')->nullable();
            $table->ulid('serial_id')->nullable();
            $table->bigInteger('quantity_delta_base');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->char('currency', 3);
            $table->timestampsTz();
            $table->index(['tenant_id', 'adjustment_request_id'], 'inv_adjustment_line_request_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresControls();
        }
    }

    public function down(): void
    {
        foreach ([
            'inventory_adjustment_lines', 'inventory_adjustment_requests', 'inventory_count_lines',
            'inventory_count_sheets', 'inventory_count_plans', 'inventory_reorder_points',
            'inventory_return_lines', 'inventory_returns', 'inventory_transfer_lines',
            'inventory_transfers', 'inventory_goods_receipt_lines', 'inventory_goods_receipts',
            'inventory_reservations', 'inventory_stock_movements', 'inventory_serials',
            'inventory_lots', 'inventory_bins', 'inventory_stock_locations', 'inventory_warehouses',
            'inventory_items', 'inventory_item_categories', 'inventory_units_of_measure',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS vtsa_prevent_stock_movement_mutation()');
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
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inv_item_tracking_chk CHECK (tracking_type IN ('none', 'lot', 'serial'))");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inv_item_valuation_chk CHECK (valuation_method IN ('moving_average', 'standard'))");
        DB::statement("ALTER TABLE inventory_stock_locations ADD CONSTRAINT inv_location_custody_chk CHECK (custody_type IN ('available', 'quarantine', 'in_transit', 'returns', 'damaged'))");
        DB::statement('ALTER TABLE inventory_stock_movements ADD CONSTRAINT inv_movement_qty_chk CHECK (quantity_base > 0 AND total_cost_minor = quantity_base * unit_cost_minor AND (from_bin_id IS NOT NULL OR to_bin_id IS NOT NULL) AND from_bin_id IS DISTINCT FROM to_bin_id)');
        DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inv_reservation_qty_chk CHECK (requested_quantity_base > 0 AND reserved_quantity_base <= requested_quantity_base AND consumed_quantity_base <= reserved_quantity_base)');
        DB::statement('ALTER TABLE inventory_goods_receipt_lines ADD CONSTRAINT inv_receipt_line_qty_chk CHECK (received_quantity_base > 0 AND accepted_quantity_base + rejected_quantity_base <= received_quantity_base)');
        DB::statement('ALTER TABLE inventory_transfer_lines ADD CONSTRAINT inv_transfer_line_qty_chk CHECK (requested_quantity_base > 0 AND dispatched_quantity_base <= requested_quantity_base AND received_quantity_base <= dispatched_quantity_base)');
        DB::statement('ALTER TABLE inventory_adjustment_lines ADD CONSTRAINT inv_adjustment_nonzero_chk CHECK (quantity_delta_base <> 0)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION vtsa_prevent_stock_movement_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock movements are immutable; post a compensating movement';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER inventory_stock_movements_immutable BEFORE UPDATE OR DELETE ON inventory_stock_movements FOR EACH ROW EXECUTE FUNCTION vtsa_prevent_stock_movement_mutation()');
    }
};
