<?php

declare(strict_types=1);

namespace Tests\Feature\ProcurementInventory;

use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\GoodsReceiptWorkflow;
use App\Modules\Inventory\Application\InventoryCatalogCsvService;
use App\Modules\Inventory\Application\StockAvailabilityQuery;
use App\Modules\Inventory\Application\StockCountWorkflow;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Application\StockReservationService;
use App\Modules\Inventory\Application\StockTransferWorkflow;
use App\Modules\Inventory\Application\SupplierReturnWorkflow;
use App\Modules\Inventory\Application\WorkOrderPartsService;
use App\Modules\Inventory\Domain\CountStatus;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Procurement\Application\PurchaseRequestWorkflow;
use App\Modules\Procurement\Application\VendorInvoiceWorkflow;
use App\Modules\Procurement\Domain\Models\ApprovalRule;
use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Procurement\Domain\Models\Department;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Domain\Models\SupplierReturn;
use App\Modules\Procurement\Domain\Models\ThreeWayMatch;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\PurchaseRequestStatus;
use App\Modules\Procurement\Domain\VendorInvoiceStatus;
use App\Modules\Procurement\Notifications\ApprovalRequiredNotification;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class ProcurementInventoryWorkflowsTest extends TenantSecurityTestCase
{
    public function test_stock_is_derived_from_idempotent_immutable_movements_and_quarantine_is_unavailable(): void
    {
        $tenant = $this->createTenant('inventory-ledger');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $stock = $this->inventorySetup();
            $ledger = app(StockLedger::class);
            $first = $this->receiveStock($stock, 10, 'receipt-available-1');
            $same = $this->receiveStock($stock, 10, 'receipt-available-1');
            $this->assertSame($first->getKey(), $same->getKey());
            $this->assertSame(1, StockMovement::query()->count());

            $ledger->post([
                'item_id' => (string) $stock['item']->getKey(),
                'uom_id' => (string) $stock['uom']->getKey(),
                'to_bin_id' => (string) $stock['quarantine_bin']->getKey(),
                'movement_type' => MovementType::Receipt,
                'quantity_base' => 3,
                'currency' => 'PHP',
                'unit_cost_minor' => 100,
                'reference_type' => 'test_receipt',
                'reference_id' => (string) Str::ulid(),
                'reason_code' => 'inspection_rejected',
                'idempotency_key' => 'receipt-quarantine-1',
            ]);
            $availability = app(StockAvailabilityQuery::class)
                ->forItem((string) $stock['item']->getKey(), (string) $stock['warehouse']->getKey());
            $this->assertSame(13, $availability->onHandBase);
            $this->assertSame(10, $availability->availableBase());

            try {
                $first->update(['quantity_base' => 999]);
                $this->fail('An immutable stock movement was changed.');
            } catch (DomainException) {
                $this->assertSame(10, $first->refresh()->quantity_base);
            }
        });
    }

    public function test_reservations_prevent_over_issue_and_work_order_parts_are_recoverable(): void
    {
        $tenant = $this->createTenant('inventory-reservations');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $stock = $this->inventorySetup();
            $this->receiveStock($stock, 10, 'work-order-receipt');
            $workOrderId = (string) Str::ulid();
            $reservation = app(StockReservationService::class)->reserve([
                'item_id' => (string) $stock['item']->getKey(),
                'warehouse_id' => (string) $stock['warehouse']->getKey(),
                'bin_id' => (string) $stock['available_bin']->getKey(),
                'requested_quantity_base' => 7,
                'purpose_type' => 'maintenance_work_order',
                'purpose_id' => $workOrderId,
                'idempotency_key' => 'work-order-reservation-1',
            ]);
            $this->assertSame(ReservationStatus::Reserved, $reservation->status);

            try {
                app(StockLedger::class)->post([
                    'item_id' => (string) $stock['item']->getKey(),
                    'uom_id' => (string) $stock['uom']->getKey(),
                    'from_bin_id' => (string) $stock['available_bin']->getKey(),
                    'movement_type' => MovementType::Issue,
                    'quantity_base' => 4,
                    'currency' => 'PHP',
                    'unit_cost_minor' => 100,
                    'reference_type' => 'unreserved_issue',
                    'reference_id' => (string) Str::ulid(),
                    'idempotency_key' => 'unreserved-over-issue',
                ]);
                $this->fail('Reserved stock was issued to an unrelated purpose.');
            } catch (DomainException) {
                $this->assertSame(3, app(StockAvailabilityQuery::class)->forItem(
                    (string) $stock['item']->getKey(),
                    (string) $stock['warehouse']->getKey(),
                )->availableBase());
            }

            app(WorkOrderPartsService::class)->issue(
                $reservation,
                (string) $stock['available_bin']->getKey(),
                5,
                'work-order-issue-1',
            );
            $this->assertSame(
                ReservationStatus::PartiallyConsumed,
                $reservation->refresh()->status,
            );
            app(WorkOrderPartsService::class)->returnUnused(
                $workOrderId,
                (string) $stock['item']->getKey(),
                (string) $stock['uom']->getKey(),
                (string) $stock['available_bin']->getKey(),
                2,
                100,
                'PHP',
                'work-order-return-1',
            );
            $availability = app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
            );
            $this->assertSame(7, $availability->onHandBase);
            $this->assertSame(2, $availability->reservedBase);
            $this->assertSame(5, $availability->availableBase());
        });
    }

    public function test_transfer_uses_in_transit_custody_and_supports_partial_receipt(): void
    {
        $tenant = $this->createTenant('inventory-transfers');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $stock = $this->inventorySetup();
            $destination = $this->warehouse('DEST', 'Destination', 'available');
            $transit = $this->warehouse('TRANSIT', 'In transit', 'in_transit', 'in_transit');
            $this->receiveStock($stock, 5, 'transfer-opening-stock');

            $workflow = app(StockTransferWorkflow::class);
            $transfer = $workflow->create('TR-0001', (string) $stock['warehouse']->getKey(), (string) $destination['warehouse']->getKey(), [[
                'item_id' => (string) $stock['item']->getKey(),
                'uom_id' => (string) $stock['uom']->getKey(),
                'source_bin_id' => (string) $stock['available_bin']->getKey(),
                'in_transit_bin_id' => (string) $transit['bin']->getKey(),
                'destination_bin_id' => (string) $destination['bin']->getKey(),
                'requested_quantity_base' => 5,
            ]]);
            $workflow->dispatch($workflow->approve($workflow->submit($transfer)));
            $this->assertSame(0, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
            )->onHandBase);
            $this->assertSame(5, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $transit['warehouse']->getKey(),
            )->onHandBase);

            $line = $transfer->lines()->firstOrFail();
            $partial = $workflow->receive($transfer->refresh(), [(string) $line->getKey() => 2]);
            $this->assertSame(TransferStatus::Discrepancy, $partial->status);
            $complete = $workflow->receive($partial, [(string) $line->getKey() => 3]);
            $this->assertSame(TransferStatus::Received, $complete->status);
            $this->assertSame(5, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $destination['warehouse']->getKey(),
            )->onHandBase);
            $this->assertSame(0, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $transit['warehouse']->getKey(),
            )->onHandBase);
        });
    }

    public function test_partial_goods_receipt_inspection_and_supplier_return_update_po_through_contracts(): void
    {
        $tenant = $this->createTenant('inventory-receiving');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $stock = $this->inventorySetup();
            [$order, $line, $supplier] = $this->issuedPurchaseOrder($stock, 5, 100);
            $workflow = app(GoodsReceiptWorkflow::class);
            $receipt = $workflow->receive([
                'purchase_order_id' => (string) $order->getKey(),
                'supplier_id' => (string) $supplier->getKey(),
                'warehouse_id' => (string) $stock['warehouse']->getKey(),
                'receipt_number' => 'GR-0001',
                'lines' => [[
                    'purchase_order_line_id' => (string) $line->getKey(),
                    'item_id' => (string) $stock['item']->getKey(),
                    'uom_id' => (string) $stock['uom']->getKey(),
                    'destination_bin_id' => (string) $stock['available_bin']->getKey(),
                    'rejection_bin_id' => (string) $stock['quarantine_bin']->getKey(),
                    'received_quantity_base' => 3,
                ]],
            ]);
            $receiptLine = $receipt->lines->firstOrFail();
            $workflow->inspect($receipt, [[
                'line_id' => (string) $receiptLine->getKey(),
                'accepted_quantity_base' => 2,
                'rejected_quantity_base' => 1,
                'inspection_notes' => 'One unit damaged in transit.',
            ]]);
            $posted = $workflow->post($receipt->refresh());
            $this->assertSame('posted_with_rejections', $posted->status);
            $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->refresh()->status);
            $this->assertSame(3, $line->refresh()->received_quantity_base);
            $this->assertSame(2, $line->accepted_quantity_base);
            $this->assertSame(3, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
            )->onHandBase);
            $this->assertSame(2, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
            )->availableBase());

            $return = app(SupplierReturnWorkflow::class)->returnRejected(
                $posted,
                'RTV-0001',
                'Inspection rejection',
            );
            $this->assertSame('posted', $return->status);
            $this->assertTrue(SupplierReturn::query()->where('inventory_return_id', $return->getKey())->exists());
            $this->assertSame(2, app(StockAvailabilityQuery::class)->forItem(
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
            )->onHandBase);
        });
    }

    public function test_approval_matrix_is_sequential_role_bound_not_self_approved_and_audited(): void
    {
        Notification::fake();
        $tenant = $this->createTenant('procurement-approvals');
        $requester = $this->createUser();
        $approverOne = $this->createUser();
        $approverTwo = $this->createUser();
        $memberships = $this->withinTenant($tenant, $requester, fn (): array => [
            'requester' => $this->createMembership($tenant, $requester),
            'one' => $this->createMembership($tenant, $approverOne),
            'two' => $this->createMembership($tenant, $approverTwo),
        ]);
        $request = $this->withinTenant($tenant, $requester, function () use ($tenant, $memberships): PurchaseRequest {
            $stock = $this->inventorySetup();
            $department = Department::factory()->create();
            $costCenter = CostCenter::factory()->create();
            $roleOne = $this->createRole($tenant, 'approver-one', [PermissionKey::ProcurementApprove]);
            $roleTwo = $this->createRole($tenant, 'approver-two', [PermissionKey::ProcurementApprove]);
            $this->assignDirectly($tenant, $memberships['one'], $roleOne);
            $this->assignDirectly($tenant, $memberships['two'], $roleTwo);
            foreach ([['approver-one', 1], ['approver-two', 2]] as [$key, $sequence]) {
                ApprovalRule::query()->create([
                    'document_type' => 'purchase_request',
                    'department_id' => $department->getKey(),
                    'cost_center_id' => $costCenter->getKey(),
                    'currency' => 'PHP',
                    'minimum_amount_minor' => 0,
                    'approver_role_key' => $key,
                    'sequence' => $sequence,
                    'is_active' => true,
                ]);
            }
            $workflow = app(PurchaseRequestWorkflow::class);
            $request = $workflow->create([
                'request_number' => 'PR-0001',
                'department_id' => (string) $department->getKey(),
                'cost_center_id' => (string) $costCenter->getKey(),
                'currency' => 'PHP',
                'business_reason' => 'Replenish critical charging spare parts.',
                'lines' => [[
                    'item_id' => (string) $stock['item']->getKey(),
                    'uom_id' => (string) $stock['uom']->getKey(),
                    'description' => 'Replacement charging component',
                    'quantity_base' => 2,
                    'estimated_unit_minor' => 5000,
                ]],
            ]);

            return $workflow->submit($request);
        });
        Notification::assertSentTo($approverOne, ApprovalRequiredNotification::class);

        $this->withinTenant($tenant, $approverOne, function () use ($request): void {
            $updated = app(PurchaseRequestWorkflow::class)->approve($request, 'approver-one');
            $this->assertSame(PurchaseRequestStatus::UnderApproval, $updated->status);
        });
        Notification::assertSentTo($approverTwo, ApprovalRequiredNotification::class);
        $this->withinTenant($tenant, $approverTwo, function () use ($request): void {
            $approved = app(PurchaseRequestWorkflow::class)->approve($request->refresh(), 'approver-two');
            $this->assertSame(PurchaseRequestStatus::Approved, $approved->status);
            $this->assertDatabaseHas('integration_outbox_events', [
                'aggregate_id' => $approved->getKey(),
                'event_type' => 'procurement.requisition.approved.v1',
            ]);
            $this->assertDatabaseHas('audit_events', [
                'target_id' => $approved->getKey(),
                'action' => 'procurement.purchase_request.approved',
            ]);
        });
    }

    public function test_three_way_match_opens_discrepancy_and_retains_immutable_evidence(): void
    {
        Notification::fake();
        $tenant = $this->createTenant('procurement-matching');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $stock = $this->inventorySetup();
            [$order, $line] = $this->issuedPurchaseOrder($stock, 2, 100);
            $line->forceFill(['received_quantity_base' => 2, 'accepted_quantity_base' => 2])->save();
            $workflow = app(VendorInvoiceWorkflow::class);
            $invoice = $workflow->create($order, [
                'invoice_reference' => 'INV-MISMATCH',
                'currency' => 'PHP',
                'invoice_date' => now('UTC')->format('Y-m-d'),
                'lines' => [[
                    'purchase_order_line_id' => (string) $line->getKey(),
                    'quantity_base' => 2,
                    'unit_price_minor' => 120,
                ]],
            ]);
            $match = $workflow->match($invoice);
            $this->assertSame('discrepancy', $match->status);
            $this->assertSame(VendorInvoiceStatus::Discrepancy, $invoice->refresh()->status);
            $this->assertGreaterThanOrEqual(2, $match->discrepancies()->count());
            try {
                $match->update(['status' => 'matched']);
                $this->fail('Three-way evidence was mutated.');
            } catch (DomainException) {
                $this->assertSame('discrepancy', $match->refresh()->status);
            }

            $correct = $workflow->create($order, [
                'invoice_reference' => 'INV-CORRECT',
                'currency' => 'PHP',
                'invoice_date' => now('UTC')->format('Y-m-d'),
                'lines' => [[
                    'purchase_order_line_id' => (string) $line->getKey(),
                    'quantity_base' => 2,
                    'unit_price_minor' => 100,
                ]],
            ]);
            $matched = $workflow->match($correct);
            $this->assertSame('matched', $matched->status);
            $approved = $workflow->approve($correct->refresh());
            $exported = $workflow->export($approved, 'vendor-export-1');
            $this->assertSame(VendorInvoiceStatus::Exported, $exported->status);
            $this->assertSame('exported', $exported->accounting_export_state);
            $this->assertSame(2, ThreeWayMatch::query()->count());
        });
    }

    public function test_blind_count_recount_approval_posts_only_movement_adjustments(): void
    {
        $tenant = $this->createTenant('inventory-counts');
        $counter = $this->createUser();
        $approver = $this->createUser();
        $plan = $this->withinTenant($tenant, $counter, function (): CountPlan {
            $stock = $this->inventorySetup();
            $this->receiveStock($stock, 10, 'count-opening-stock');
            $workflow = app(StockCountWorkflow::class);
            $plan = $workflow->create(
                'COUNT-0001',
                'cycle',
                (string) $stock['warehouse']->getKey(),
                now('UTC')->format('Y-m-d'),
                true,
                [[
                    'stock_location_id' => (string) $stock['available_location']->getKey(),
                    'lines' => [[
                        'item_id' => (string) $stock['item']->getKey(),
                        'bin_id' => (string) $stock['available_bin']->getKey(),
                    ]],
                ]],
            );
            $workflow->start($plan);
            $sheet = $plan->sheets()->with('lines')->firstOrFail();
            $line = $sheet->lines->firstOrFail();
            $workflow->submitSheet($sheet, [(string) $line->getKey() => 12]);
            $this->assertSame(CountStatus::RecountRequired, $plan->refresh()->status);
            $recount = $workflow->createRecount($sheet);
            $recountLine = $recount->lines->firstOrFail();
            $workflow->submitSheet($recount, [(string) $recountLine->getKey() => 12], 99);
            $this->assertSame(CountStatus::Review, $plan->refresh()->status);

            return $plan;
        });

        $this->withinTenant($tenant, $approver, function () use ($plan): void {
            $workflow = app(StockCountWorkflow::class);
            $approved = $workflow->approve($plan->refresh());
            $posted = $workflow->post($approved);
            $this->assertSame(CountStatus::Posted, $posted->status);
            $this->assertDatabaseHas('inventory_adjustment_requests', [
                'source_type' => 'inventory_count_plan',
                'source_id' => $posted->getKey(),
                'status' => 'posted',
            ]);
            $itemId = (string) $posted->sheets()->firstOrFail()->lines()->firstOrFail()->item_id;
            $this->assertSame(12, app(StockAvailabilityQuery::class)->forItem($itemId)->onHandBase);
            $this->assertSame(2, StockMovement::query()->whereIn('movement_type', [
                MovementType::Receipt->value,
                MovementType::AdjustmentIncrease->value,
            ])->count());
        });
    }

    public function test_tenant_and_warehouse_scope_apply_to_queries_reports_and_csv_imports(): void
    {
        $tenantA = $this->createTenant('inventory-scope-a');
        $tenantB = $this->createTenant('inventory-scope-b');
        $actor = $this->createUser();
        $scopedUser = $this->createUser();
        $membership = $this->withinTenant(
            $tenantA,
            $actor,
            fn () => $this->createMembership($tenantA, $scopedUser),
        );
        $warehouseA = $this->withinTenant($tenantA, $actor, function () use ($tenantA, $membership): string {
            $stock = $this->inventorySetup();
            $role = $this->createRole($tenantA, 'warehouse-reader', [PermissionKey::InventoryView]);
            $this->assignDirectly(
                $tenantA,
                $membership,
                $role,
                new ResourceScope(ScopeType::Warehouse, (string) $stock['warehouse']->getKey()),
            );
            $csv = app(InventoryCatalogCsvService::class);
            $content = $csv->template()
                ."CSV-001,Imported spare,Safe local test item,SPARES,EA,none,moving_average,PHP,1250\n";
            $result = $csv->import($content);
            $this->assertSame(1, $result['created']);
            $this->assertSame(0, count($result['errors']));
            $duplicate = $csv->import($content);
            $this->assertSame(0, $duplicate['created']);
            $this->assertSame(1, count($duplicate['errors']));
            $this->assertStringContainsString('CSV-001', $csv->export());
            $factoryItem = InventoryItem::factory()->create();
            $this->assertSame($tenantA->getKey(), $factoryItem->tenant_id);

            return (string) $stock['warehouse']->getKey();
        });
        $this->withinTenant($tenantB, $actor, function (): void {
            $this->inventorySetup();
            $this->assertSame(1, Warehouse::query()->count());
            $this->assertSame(1, InventoryItem::query()->count());
        });
        $this->withinTenant($tenantA, $actor, function () use ($scopedUser, $warehouseA): void {
            $ids = app(AccessibleWarehousesQuery::class)->for($scopedUser)->pluck('id')->all();
            $this->assertSame([$warehouseA], $ids);
            $this->assertSame(1, Warehouse::query()->count());
            $this->assertSame(3, InventoryItem::query()->count());
        });
    }

    public function test_mobile_api_intersects_token_permission_with_warehouse_and_tenant_scope(): void
    {
        $tenantA = $this->createTenant('inventory-api-a');
        $tenantB = $this->createTenant('inventory-api-b');
        $user = $this->createUser();
        [$warehouseId, $otherWarehouseId, $itemId] = $this->withinTenant(
            $tenantA,
            $user,
            function () use ($tenantA, $user): array {
                $stock = $this->inventorySetup();
                $other = $this->warehouse('OTHER', 'Other warehouse', 'available');
                $membership = $this->createMembership($tenantA, $user);
                $role = $this->createRole($tenantA, 'inventory-api-reader', [
                    PermissionKey::InventoryView,
                    PermissionKey::ProcurementView,
                ]);
                $this->assignDirectly(
                    $tenantA,
                    $membership,
                    $role,
                    new ResourceScope(ScopeType::Warehouse, (string) $stock['warehouse']->getKey()),
                );
                $this->receiveStock($stock, 3, 'api-visible-stock');
                app(StockLedger::class)->post([
                    'item_id' => (string) $stock['item']->getKey(),
                    'uom_id' => (string) $stock['uom']->getKey(),
                    'to_bin_id' => (string) $other['bin']->getKey(),
                    'movement_type' => MovementType::Receipt,
                    'quantity_base' => 4,
                    'currency' => 'PHP',
                    'unit_cost_minor' => 100,
                    'reference_type' => 'opening_receipt',
                    'reference_id' => (string) Str::ulid(),
                    'idempotency_key' => 'api-hidden-stock',
                ]);

                return [
                    (string) $stock['warehouse']->getKey(),
                    (string) $other['warehouse']->getKey(),
                    (string) $stock['item']->getKey(),
                ];
            },
        );
        $otherTenantRequestId = $this->withinTenant($tenantB, $user, function (): string {
            $stock = $this->inventorySetup();
            [$order] = $this->issuedPurchaseOrder($stock, 1, 100);

            return (string) $order->purchase_request_id;
        });
        $token = $this->login($user, $tenantA);
        $this->withToken($token)->getJson('/api/v1/inventory/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouseId)
            ->assertJsonMissing(['id' => $otherWarehouseId]);
        $this->withToken($token)->getJson('/api/v1/inventory/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson(
            "/api/v1/inventory/items/{$itemId}/availability?warehouse_id={$otherWarehouseId}",
        )->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/purchase-requests/'.$otherTenantRequestId)
            ->assertNotFound();
    }

    /**
     * @return array{
     *   uom: UnitOfMeasure, item: InventoryItem, warehouse: Warehouse,
     *   available_location: StockLocation, available_bin: InventoryBin,
     *   quarantine_bin: InventoryBin
     * }
     */
    private function inventorySetup(): array
    {
        $uom = UnitOfMeasure::query()->create([
            'code' => 'EA',
            'name' => 'Each',
            'dimension' => 'count',
            'base_multiplier' => 1,
            'is_active' => true,
        ]);
        $category = ItemCategory::query()->create(['code' => 'SPARES', 'name' => 'Spare parts']);
        $item = InventoryItem::query()->create([
            'category_id' => $category->getKey(),
            'base_uom_id' => $uom->getKey(),
            'sku' => 'SPARE-001',
            'name' => 'Charging spare',
            'tracking_type' => 'none',
            'valuation_method' => 'moving_average',
            'currency' => 'PHP',
            'standard_cost_minor' => 100,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main warehouse',
            'type' => 'warehouse',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);
        $available = StockLocation::query()->create([
            'warehouse_id' => $warehouse->getKey(),
            'code' => 'AVAILABLE',
            'name' => 'Available',
            'custody_type' => 'available',
            'is_active' => true,
        ]);
        $quarantine = StockLocation::query()->create([
            'warehouse_id' => $warehouse->getKey(),
            'code' => 'QUARANTINE',
            'name' => 'Quarantine',
            'custody_type' => 'quarantine',
            'is_active' => true,
        ]);

        return [
            'uom' => $uom,
            'item' => $item,
            'warehouse' => $warehouse,
            'available_location' => $available,
            'available_bin' => InventoryBin::query()->create([
                'warehouse_id' => $warehouse->getKey(),
                'stock_location_id' => $available->getKey(),
                'code' => 'A-01',
                'name' => 'Available bin',
                'is_active' => true,
            ]),
            'quarantine_bin' => InventoryBin::query()->create([
                'warehouse_id' => $warehouse->getKey(),
                'stock_location_id' => $quarantine->getKey(),
                'code' => 'Q-01',
                'name' => 'Quarantine bin',
                'is_active' => true,
            ]),
        ];
    }

    /** @return array{warehouse: Warehouse, location: StockLocation, bin: InventoryBin} */
    private function warehouse(string $code, string $name, string $custody, string $type = 'warehouse'): array
    {
        $warehouse = Warehouse::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $location = StockLocation::query()->create([
            'warehouse_id' => $warehouse->getKey(),
            'code' => $code,
            'name' => $name,
            'custody_type' => $custody,
            'is_active' => true,
        ]);

        return [
            'warehouse' => $warehouse,
            'location' => $location,
            'bin' => InventoryBin::query()->create([
                'warehouse_id' => $warehouse->getKey(),
                'stock_location_id' => $location->getKey(),
                'code' => $code.'-BIN',
                'name' => $name,
                'is_active' => true,
            ]),
        ];
    }

    /**
     * @param array{
     *   uom: UnitOfMeasure, item: InventoryItem, warehouse: Warehouse,
     *   available_location: StockLocation, available_bin: InventoryBin,
     *   quarantine_bin: InventoryBin
     * } $stock
     */
    private function receiveStock(array $stock, int $quantity, string $idempotencyKey): StockMovement
    {
        return app(StockLedger::class)->post([
            'item_id' => (string) $stock['item']->getKey(),
            'uom_id' => (string) $stock['uom']->getKey(),
            'to_bin_id' => (string) $stock['available_bin']->getKey(),
            'movement_type' => MovementType::Receipt,
            'quantity_base' => $quantity,
            'currency' => 'PHP',
            'unit_cost_minor' => 100,
            'reference_type' => 'opening_receipt',
            'reference_id' => (string) Str::ulid(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * @param array{
     *   uom: UnitOfMeasure, item: InventoryItem, warehouse: Warehouse,
     *   available_location: StockLocation, available_bin: InventoryBin,
     *   quarantine_bin: InventoryBin
     * } $stock
     * @return array{PurchaseOrder, PurchaseOrderLine, Supplier}
     */
    private function issuedPurchaseOrder(array $stock, int $quantity, int $unitPriceMinor): array
    {
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operations', 'is_active' => true]);
        $costCenter = CostCenter::query()->create(['code' => 'NETWORK', 'name' => 'Network', 'is_active' => true]);
        $supplier = Supplier::query()->create(['code' => 'SUP-1', 'name' => 'Test Supplier', 'status' => 'active']);
        $request = PurchaseRequest::query()->create([
            'request_number' => 'PR-PO-1',
            'requested_by' => app(CurrentTenant::class)->get()->actorId,
            'department_id' => $department->getKey(),
            'cost_center_id' => $costCenter->getKey(),
            'status' => PurchaseRequestStatus::Ordered,
            'currency' => 'PHP',
            'total_minor' => $quantity * $unitPriceMinor,
            'business_reason' => 'Test purchase order',
        ]);
        $order = PurchaseOrder::query()->create([
            'purchase_request_id' => $request->getKey(),
            'supplier_id' => $supplier->getKey(),
            'po_number' => 'PO-1',
            'status' => PurchaseOrderStatus::Issued,
            'currency' => 'PHP',
            'subtotal_minor' => $quantity * $unitPriceMinor,
            'tax_minor' => 0,
            'shipping_minor' => 0,
            'total_minor' => $quantity * $unitPriceMinor,
            'delivery_warehouse_id' => $stock['warehouse']->getKey(),
            'created_by' => app(CurrentTenant::class)->get()->actorId,
            'issued_at' => now('UTC'),
        ]);
        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->getKey(),
            'item_id' => $stock['item']->getKey(),
            'uom_id' => $stock['uom']->getKey(),
            'description' => 'Charging spare',
            'ordered_quantity_base' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'line_total_minor' => $quantity * $unitPriceMinor,
        ]);

        return [$order, $line, $supplier];
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Procurement inventory test',
        ])->assertOk()->json('data.token');
    }
}
