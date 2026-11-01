<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\CountPlans\Pages\ListCountPlans;
use App\Filament\Operator\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Operator\Resources\InventoryAdjustments\Pages\ListInventoryAdjustments;
use App\Filament\Operator\Resources\MaintenanceIncidents\Pages\ListMaintenanceIncidents;
use App\Filament\Operator\Resources\PreventivePlans\Pages\ListPreventivePlans;
use App\Filament\Operator\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Operator\Resources\SessionReviews\Pages\ListSessionReviews;
use App\Filament\Operator\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Operator\Resources\VendorInvoices\Pages\ListVendorInvoices;
use App\Filament\Operator\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ChargingSessionReview;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Maintenance\Application\PreventivePlanManager;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Procurement\Application\PurchaseRequestWorkflow;
use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Procurement\Domain\Models\Department;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use App\Modules\Procurement\Domain\PurchaseRequestStatus;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Seeders\MasterDataSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class AdminWorkflowActionsTest extends TenantSecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Notification::fake();
        $this->tenant = $this->createTenant('admin-workflows');
        $this->admin = $this->createUser();
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            Organization::factory()->create(['type' => 'platform']);
            $this->assignDirectly($this->tenant, $this->createMembership($this->tenant, $this->admin), $this->createRole($this->tenant, 'admin', PermissionKey::cases()));
        });
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{site_id:string,asset_type:string,asset_id:string,priority_id:string} */
    private function asset(): array
    {
        $station = ChargingStation::factory()->create();

        return ['site_id' => (string) $station->site_id, 'asset_type' => 'station', 'asset_id' => (string) $station->getKey(), 'priority_id' => (string) MaintenancePriority::factory()->create()->getKey()];
    }

    public function test_preventive_plan_create_edit_pause_preserves_history_and_validates_intervals(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $data = $this->asset() + ['name' => 'Monthly safety inspection', 'trigger_type' => 'date', 'interval_value' => 2592000, 'next_due_at' => now()->addDay()->format('Y-m-d H:i:s')];
            Livewire::test(ListPreventivePlans::class)->callAction('createPlan', data: array_replace($data, ['interval_value' => 0]))->assertHasActionErrors(['interval_value']);
            Livewire::test(ListPreventivePlans::class)->callAction('createPlan', data: $data)->assertHasNoActionErrors();
            $plan = PreventivePlan::query()->sole();
            Livewire::test(ListPreventivePlans::class)->callTableAction('editPlan', $plan, data: array_replace($data, ['name' => 'Updated inspection']))->assertHasNoActionErrors();
            Livewire::test(ListPreventivePlans::class)->callTableAction('togglePlan', $plan, data: ['reason' => 'Station is undergoing refurbishment'])->assertHasNoActionErrors();
            $this->assertFalse($plan->fresh()->is_active);
            $this->assertSame('Updated inspection', $plan->fresh()->name);
            $this->assertDatabaseHas('audit_events', ['action' => 'maintenance.preventive_plan.paused', 'target_id' => $plan->getKey()]);
        });
    }

    public function test_work_order_and_incident_can_be_created_from_portal(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $data = $this->asset();
            Livewire::test(ListWorkOrders::class)->callAction('createWorkOrder', data: $data + ['work_type' => 'corrective', 'title' => 'Cable inspection', 'description' => 'Inspect damaged cable sheath', 'verification_required' => true, 'safety_critical' => true, 'asset_restriction_required' => false])->assertHasNoActionErrors();
            $this->assertSame(1, WorkOrder::query()->count());
            Livewire::test(ListMaintenanceIncidents::class)->callAction('createIncident', data: $data + ['title' => 'Cable damage', 'fault_code' => 'CABLE_DAMAGE', 'details' => 'Reported by staff'])->assertHasNoActionErrors();
            $this->assertSame(1, Incident::query()->count());
        });
    }

    public function test_plan_management_denies_other_site_and_other_tenant(): void
    {
        $other = $this->createTenant('other-plan');
        $foreign = $this->withinTenant($other, $this->admin, fn () => PreventivePlan::factory()->create());
        $this->withinTenant($this->tenant, $this->admin, function () use ($foreign): void {
            $plan = PreventivePlan::factory()->create();
            $reader = $this->createUser();
            $membership = $this->createMembership($this->tenant, $reader);
            $this->assignDirectly($this->tenant, $membership, $this->createRole($this->tenant, 'restricted', [PermissionKey::MaintenanceDispatch]), new ResourceScope(ScopeType::Site, (string) ChargingStation::factory()->create()->site_id));
            try {
                app(PreventivePlanManager::class)->setActive($reader, $plan, false, 'Wrong site');
                $this->fail('Other site changed');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $this->expectException(ModelNotFoundException::class);
            app(PreventivePlanManager::class)->setActive($this->admin, $foreign, false, 'Wrong tenant');
        });
    }

    public function test_purchase_draft_edit_and_cancel_are_audited_and_approved_records_are_locked(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $item = InventoryItem::factory()->create();
            $data = ['department_id' => Department::factory()->create()->getKey(), 'cost_center_id' => CostCenter::factory()->create()->getKey(), 'currency' => 'PHP', 'needed_by' => now()->addWeek()->format('Y-m-d'), 'business_reason' => 'Replace worn cables', 'lines' => [['item_id' => $item->getKey(), 'uom_id' => $item->base_uom_id, 'description' => 'Cable', 'quantity_base' => 2, 'estimated_unit_minor' => 12500]]];
            Livewire::test(ListPurchaseRequests::class)->callAction('createRequest', data: $data)->assertHasNoActionErrors();
            $request = PurchaseRequest::query()->sole();
            $this->assertSame(25000, $request->total_minor);
            $data['lines'][0]['quantity_base'] = 3;
            Livewire::test(ListPurchaseRequests::class)->callTableAction('editDraft', $request, data: $data)->assertHasNoActionErrors();
            $this->assertSame(37500, $request->fresh()->total_minor);
            $this->assertSame($item->getKey(), $request->lines()->sole()->item_id);
            Livewire::test(ListPurchaseRequests::class)->callTableAction('cancelRequest', $request, data: ['reason' => 'No longer required'])->assertHasNoActionErrors();
            $this->assertSame(PurchaseRequestStatus::Canceled, $request->fresh()->status);
            $this->assertSame(1, $request->lines()->count());
            $request->forceFill(['status' => PurchaseRequestStatus::Approved])->save();
            $this->expectException(DomainException::class);
            app(PurchaseRequestWorkflow::class)->updateDraft($request, $data);
        });
    }

    public function test_adjustment_requires_independent_approval_and_cannot_post_early(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $bin = InventoryBin::factory()->create();
            $item = InventoryItem::factory()->create();
            Livewire::test(ListInventoryAdjustments::class)->callAction('createAdjustment', data: ['warehouse_id' => $bin->warehouse_id, 'reason_code' => 'count_variance', 'reason_notes' => 'One extra part counted', 'lines' => [['item_id' => $item->getKey(), 'uom_id' => $item->base_uom_id, 'bin_id' => $bin->getKey(), 'quantity_delta_base' => 1]]])->assertHasNoActionErrors();
            $adjustment = AdjustmentRequest::query()->sole();
            Livewire::test(ListInventoryAdjustments::class)->callTableAction('approve', $adjustment)->assertNotified('Action could not be completed');
            $this->assertSame('pending_approval', $adjustment->fresh()->status);
            Livewire::test(ListInventoryAdjustments::class)->callTableAction('reject', $adjustment, data: ['reason' => 'Recount first'])->assertHasNoActionErrors();
            $this->assertSame('rejected', $adjustment->fresh()->status);
        });
    }

    public function test_stock_transfer_and_count_creation_use_scoped_warehouses(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $source = InventoryBin::factory()->create();
            $destination = InventoryBin::factory()->create();
            $transitLocation = StockLocation::factory()->create(['warehouse_id' => $source->warehouse_id, 'custody_type' => 'in_transit']);
            $transit = InventoryBin::factory()->create(['stock_location_id' => $transitLocation->getKey()]);
            $item = InventoryItem::factory()->create();
            Livewire::test(ListStockTransfers::class)->callAction('createTransfer', data: ['source_warehouse_id' => $source->warehouse_id, 'destination_warehouse_id' => $destination->warehouse_id, 'lines' => [['item_id' => $item->getKey(), 'uom_id' => $item->base_uom_id, 'source_bin_id' => $source->getKey(), 'in_transit_bin_id' => $transit->getKey(), 'destination_bin_id' => $destination->getKey(), 'requested_quantity_base' => 1]]])->assertHasNoActionErrors();
            $transfer = StockTransfer::query()->sole();
            Livewire::test(ListStockTransfers::class)->callTableAction('submit', $transfer)->assertHasNoActionErrors();
            $this->assertSame('submitted', $transfer->fresh()->status->value);
            Livewire::test(ListCountPlans::class)->callAction('createCount', data: ['warehouse_id' => $source->warehouse_id, 'count_type' => 'cycle', 'scheduled_for' => now()->format('Y-m-d'), 'blind_count' => true, 'sheets' => [['stock_location_id' => $source->stock_location_id, 'lines' => [['item_id' => $item->getKey(), 'bin_id' => $source->getKey()]]]]])->assertHasNoActionErrors();
            $count = CountPlan::query()->sole();
            Livewire::test(ListCountPlans::class)->callTableAction('start', $count)->assertHasNoActionErrors();
            $this->assertSame('in_progress', $count->fresh()->status->value);
            $sheet = $count->sheets()->sole();
            Livewire::test(ListCountPlans::class)->callTableAction('recordCount', $count, data: ['sheet_id' => $sheet->getKey(), 'recount_threshold' => 0, 'counts' => [['line_id' => $sheet->lines()->sole()->getKey(), 'quantity' => 0]]])->assertHasNoActionErrors();
            $this->assertSame('review', $count->fresh()->status->value);
        });
    }

    public function test_receiving_inspection_stock_posting_and_invoice_matching_from_portal(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $bin = InventoryBin::factory()->create();
            $item = InventoryItem::factory()->create(['tracking_type' => 'none']);
            $supplier = Supplier::query()->create(['code' => 'PORTAL-SUPPLIER', 'name' => 'Supplier', 'status' => 'active']);
            $request = PurchaseRequest::query()->create(['request_number' => 'PR-RECEIVING', 'requested_by' => $this->admin->public_id, 'department_id' => Department::factory()->create()->getKey(), 'cost_center_id' => CostCenter::factory()->create()->getKey(), 'status' => PurchaseRequestStatus::Ordered, 'currency' => 'PHP', 'total_minor' => 20000, 'business_reason' => 'Charging spares']);
            $order = PurchaseOrder::query()->create(['purchase_request_id' => $request->getKey(), 'supplier_id' => $supplier->getKey(), 'po_number' => 'PO-RECEIVING', 'status' => 'issued', 'currency' => 'PHP', 'subtotal_minor' => 20000, 'tax_minor' => 0, 'shipping_minor' => 0, 'total_minor' => 20000, 'delivery_warehouse_id' => $bin->warehouse_id, 'created_by' => $this->admin->public_id, 'issued_at' => now()]);
            $line = $order->lines()->create(['item_id' => $item->getKey(), 'uom_id' => $item->base_uom_id, 'description' => 'Charging spare', 'ordered_quantity_base' => 2, 'unit_price_minor' => 10000, 'line_total_minor' => 20000]);
            $receiptPage = ListGoodsReceipts::class;
            Livewire::test($receiptPage)->callAction('receiveGoods', data: ['purchase_order_id' => $order->getKey(), 'warehouse_id' => $bin->warehouse_id, 'supplier_delivery_reference' => 'DR-1', 'lines' => [['purchase_order_line_id' => $line->getKey(), 'destination_bin_id' => $bin->getKey(), 'received_quantity_base' => 2]]])->assertHasNoActionErrors();
            $receipt = GoodsReceipt::query()->sole();
            $receiptLine = $receipt->lines()->sole();
            Livewire::test($receiptPage)->callTableAction('inspect', $receipt, data: ['accepted' => [$receiptLine->getKey() => 2], 'notes' => [$receiptLine->getKey() => 'Packaging intact']])->assertHasNoActionErrors();
            $this->assertSame('inspected', $receipt->fresh()->status);
            Livewire::test($receiptPage)->callTableAction('postReceipt', $receipt)->assertHasNoActionErrors();
            $this->assertSame('posted', $receipt->fresh()->status);
            $invoicePage = ListVendorInvoices::class;
            Livewire::test($invoicePage)->callAction('createVendorInvoice', data: ['purchase_order_id' => $order->getKey(), 'invoice_reference' => 'INV-RECEIVING', 'invoice_date' => now()->format('Y-m-d'), 'tax_minor' => 0, 'lines' => [['purchase_order_line_id' => $line->getKey(), 'quantity_base' => 2, 'unit_price_minor' => 10000]]])->assertHasNoActionErrors();
            $invoice = VendorInvoice::query()->sole();
            Livewire::test($invoicePage)->callTableAction('matchInvoice', $invoice)->assertHasNoActionErrors();
            $this->assertSame('matched', $invoice->fresh()->status->value);
            $this->assertSame(20000, $invoice->total_minor);
        });
    }

    public function test_session_review_requires_evidence_and_finalizes_through_the_charging_workflow(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $session = ChargingSession::factory()->create(['state' => 'review_required', 'currency' => 'PHP']);
            $review = ChargingSessionReview::query()->create(['session_id' => $session->getKey(), 'status' => 'open', 'reason_code' => 'missing_stop_meter', 'opened_at' => now()]);
            $page = ListSessionReviews::class;
            Livewire::test($page)->callTableAction('resolveReview', $review, data: ['decision' => 'unbillable', 'notes' => '', 'energy_wh' => -1])->assertHasActionErrors(['notes', 'energy_wh']);
            Livewire::test($page)->callTableAction('resolveReview', $review, data: ['decision' => 'unbillable', 'notes' => 'No usable device readings; reviewed with the operator.'])->assertHasNoActionErrors();
            $this->assertSame('resolved', $review->fresh()->status);
            $this->assertSame('completed', $session->fresh()->state->value);
            $this->assertSame('unbillable', $session->fresh()->finalization_outcome);
            Livewire::test($page)->assertTableActionHidden('resolveReview', $review->fresh());
            $this->assertDatabaseHas('audit_events', ['action' => 'charging.session_review.resolved', 'target_id' => $session->getKey()]);
        });
    }
}
