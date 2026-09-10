<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Models\User;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Application\FaultAutomationService;
use App\Modules\Maintenance\Application\MaintenanceEvidenceService;
use App\Modules\Maintenance\Application\MaintenanceIntakeService;
use App\Modules\Maintenance\Application\MaintenancePartService;
use App\Modules\Maintenance\Application\PreventiveMaintenanceScheduler;
use App\Modules\Maintenance\Application\WorkOrderWorkflow;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\Models\ChecklistTemplate;
use App\Modules\Maintenance\Domain\Models\ChecklistTemplateItem;
use App\Modules\Maintenance\Domain\Models\DowntimePeriod;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\IncidentRule;
use App\Modules\Maintenance\Domain\Models\MaintenanceCode;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\PreventiveOccurrence;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\Models\SlaPolicy;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Maintenance\Notifications\MaintenanceAlertNotification;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class MaintenanceWorkflowsTest extends TenantSecurityTestCase
{
    public function test_legacy_acknowledged_work_order_state_is_repaired_to_triaged(): void
    {
        $tenant = $this->createTenant('maintenance-legacy-state');
        $user = $this->createUser();

        $this->withinTenant($tenant, $user, function (): void {
            $workOrder = WorkOrder::factory()->create([
                'acknowledged_at' => now('UTC'),
            ]);
            DB::table('maintenance_work_orders')
                ->where('id', $workOrder->getKey())
                ->update(['state' => 'acknowledged']);

            $migration = require database_path('migrations/2026_07_29_001300_repair_work_order_states.php');
            $migration->up();

            self::assertSame(WorkOrderState::Triaged, $workOrder->refresh()->state);
            self::assertNotNull($workOrder->acknowledged_at);
        });
    }

    public function test_work_order_evidence_verification_downtime_and_reopening_are_safe(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-07-28T01:00:00Z');

        try {
            $tenant = $this->createTenant('maintenance-state-machine');
            $dispatcher = $this->createUser();
            $technician = $this->createUser();
            $verifier = $this->createUser();
            [$workOrder, $station, $resolution] = $this->withinTenant($tenant, $dispatcher, function () use (
                $tenant,
                $dispatcher,
                $technician,
                $verifier,
            ): array {
                $this->createMembership($tenant, $dispatcher);
                $this->createMembership($tenant, $technician);
                $this->createMembership($tenant, $verifier);
                [$site, $station] = $this->assetFixture();
                $priority = MaintenancePriority::factory()->create();
                $sla = SlaPolicy::factory()->create([
                    'priority_id' => $priority->getKey(),
                    'acknowledge_seconds' => 600,
                    'resolve_seconds' => 3600,
                ]);
                $resolution = MaintenanceCode::query()->create([
                    'type' => 'resolution',
                    'code' => 'VALIDATED',
                    'name' => 'Validated repair',
                    'is_active' => true,
                ]);
                $template = ChecklistTemplate::query()->create([
                    'name' => 'Electrical safety',
                    'version' => 1,
                    'work_type' => 'corrective',
                    'requires_independent_verification' => true,
                    'is_active' => true,
                ]);
                $templateItem = ChecklistTemplateItem::query()->create([
                    'template_id' => $template->getKey(),
                    'sort_order' => 1,
                    'item_type' => 'safety',
                    'label' => 'Validate electrical isolation',
                    'is_required' => true,
                    'requires_photo' => false,
                    'requires_pass' => true,
                ]);
                $workflow = app(WorkOrderWorkflow::class);
                $workOrder = $workflow->create([
                    'work_type' => 'corrective',
                    'site_id' => (string) $site->getKey(),
                    'asset_type' => 'station',
                    'asset_id' => (string) $station->getKey(),
                    'priority_id' => (string) $priority->getKey(),
                    'sla_policy_id' => (string) $sla->getKey(),
                    'checklist_template_id' => (string) $template->getKey(),
                    'title' => 'Investigate repeated isolation fault',
                    'description' => 'Operator-observed service interruption.',
                    'verification_required' => true,
                    'asset_restriction_required' => true,
                ]);
                self::assertSame(WorkOrderState::Reported, $workOrder->state);
                self::assertSame(1, $workOrder->transitions()->count());
                self::assertSame($templateItem->label, $workOrder->checklistItems()->firstOrFail()->label);

                $workflow->transition($workOrder, WorkOrderState::Triaged, 'dispatch_acknowledged');
                $workflow->plan($workOrder->refresh(), 'Inspect and validate the isolation path.', null, null, 'UTC');
                $workflow->assign($workOrder->refresh(), (string) $technician->public_id, null);

                return [$workOrder, $station, $resolution];
            });
            Notification::assertSentTo(
                $technician,
                MaintenanceAlertNotification::class,
                static fn (MaintenanceAlertNotification $notification): bool => $notification->toArray($technician)['kind']
                    === 'work_order_assigned',
            );

            $this->withinTenant($tenant, $technician, function () use ($workOrder, $resolution): void {
                $workflow = app(WorkOrderWorkflow::class);
                $workflow->transition($workOrder->refresh(), WorkOrderState::InProgress, 'technician_started');
                self::assertSame(AssetLifecycleStatus::Maintenance->value, $workOrder->refresh()
                    ->getConnection()->table('charging_stations')->where('id', $workOrder->asset_id)->value('lifecycle_status'));
                $workflow->completeChecklistItem(
                    $workOrder->checklistItems()->firstOrFail(),
                    'pass',
                    'Isolation verified.',
                );
                try {
                    $workflow->recordTime($workOrder->refresh(), 'labor', 60, 100, 'USD');
                    self::fail('A foreign-currency maintenance cost entered the work-order rollup.');
                } catch (DomainException $exception) {
                    self::assertSame(
                        'Maintenance cost entries must use the work-order currency.',
                        $exception->getMessage(),
                    );
                }
                $workflow->recordTime($workOrder->refresh(), 'labor', 1800, 12000, 'PHP');
                $workflow->recordTime($workOrder->refresh(), 'travel', 900, 6000, 'PHP');
                $workflow->recordResolution(
                    $workOrder->refresh(),
                    'Re-terminated and tested the affected isolation path.',
                    (string) $resolution->getKey(),
                    diagnosis: 'Loose termination found during inspection.',
                );
                $workflow->transition($workOrder->refresh(), WorkOrderState::Completed, 'work_completed');
                $workflow->transition(
                    $workOrder->refresh(),
                    WorkOrderState::VerificationRequired,
                    'independent_verification_required',
                );
                try {
                    $workflow->transition($workOrder->refresh(), WorkOrderState::Verified, 'self_verification_attempt');
                    self::fail('The completing technician verified their own work.');
                } catch (DomainException $exception) {
                    self::assertSame(
                        'Required verification must be performed by a different user.',
                        $exception->getMessage(),
                    );
                }
            });

            $this->withinTenant($tenant, $verifier, function () use ($workOrder): void {
                $workflow = app(WorkOrderWorkflow::class);
                $workflow->transition($workOrder->refresh(), WorkOrderState::Verified, 'repair_validated');
                $workflow->transition($workOrder->refresh(), WorkOrderState::Closed, 'closure_approved');
            });
            $this->withinTenant($tenant, $dispatcher, function () use ($workOrder, $station): void {
                self::assertSame(WorkOrderState::Closed, $workOrder->refresh()->state);
                self::assertSame(7500, $workOrder->actual_cost_minor);
                self::assertSame(AssetLifecycleStatus::Active, $station->refresh()->lifecycle_status);
                $downtime = DowntimePeriod::query()->where('work_order_id', $workOrder->getKey())->firstOrFail();
                self::assertNotNull($downtime->ended_at);
                self::assertSame(0, $downtime->duration_seconds);
                self::assertSame($workOrder->aggregate_version, $workOrder->transitions()->count());
                self::assertDatabaseHas('audit_events', [
                    'target_id' => $workOrder->getKey(),
                    'action' => 'maintenance.work_order.transitioned',
                ]);

                $originalTransition = $workOrder->transitions()->firstOrFail();
                try {
                    $originalTransition->update(['reason_code' => 'rewritten']);
                    self::fail('Immutable maintenance evidence was rewritten.');
                } catch (DomainException) {
                    self::assertSame('work_order_created', $originalTransition->refresh()->reason_code);
                }
                $reopened = app(WorkOrderWorkflow::class)->reopen(
                    $workOrder->refresh(),
                    'The symptom reappeared after closure.',
                );
                self::assertNotSame($workOrder->getKey(), $reopened->getKey());
                self::assertSame($workOrder->getKey(), $reopened->reopened_from_id);
                self::assertSame(WorkOrderState::Closed, $workOrder->refresh()->state);
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_selected_ocpp_faults_are_deduplicated_escalated_and_resolved_after_validated_recovery(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-07-28T02:00:00Z');

        try {
            $tenant = $this->createTenant('maintenance-faults');
            $actor = $this->createUser();
            $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
                [$site, , $connectorId] = $this->connectorFixture();
                $membership = $this->createMembership($tenant, $actor);
                $dispatcher = $this->createRole($tenant, 'fault-dispatcher', [PermissionKey::MaintenanceDispatch]);
                $this->assignDirectly(
                    $tenant,
                    $membership,
                    $dispatcher,
                    new ResourceScope(ScopeType::Site, (string) $site->getKey()),
                );
                $priority = MaintenancePriority::factory()->create();
                IncidentRule::factory()->create([
                    'fault_code' => 'GroundFailure',
                    'priority_id' => $priority->getKey(),
                    'creates_work_order' => true,
                    'recovery_after_seconds' => 60,
                    'persistent_after_seconds' => 150,
                    'escalation_after_seconds' => 120,
                ]);
                $faults = app(FaultAutomationService::class);
                $firstEvent = (string) Str::ulid();
                $faults->observe(
                    $firstEvent,
                    (string) $site->getKey(),
                    'connector',
                    $connectorId,
                    'faulted',
                    'GroundFailure',
                    CarbonImmutable::now('UTC'),
                );
                $faults->observe(
                    $firstEvent,
                    (string) $site->getKey(),
                    'connector',
                    $connectorId,
                    'faulted',
                    'GroundFailure',
                    CarbonImmutable::now('UTC'),
                );
                $faults->observe(
                    (string) Str::ulid(),
                    (string) $site->getKey(),
                    'connector',
                    $connectorId,
                    'faulted',
                    'GroundFailure',
                    CarbonImmutable::now('UTC')->addSeconds(30),
                );
                $incident = Incident::query()->firstOrFail();
                self::assertSame(2, $incident->occurrence_count);
                self::assertSame(1, WorkOrder::query()->count());
                self::assertSame(2, $incident->observations()->count());

                self::assertSame(0, $faults->escalatePersistent(CarbonImmutable::now('UTC')->addSeconds(149)));
                self::assertSame(1, $faults->escalatePersistent(CarbonImmutable::now('UTC')->addSeconds(151)));
                self::assertSame(1, $incident->refresh()->escalation_level);
                self::assertSame(0, $faults->escalatePersistent(CarbonImmutable::now('UTC')->addSeconds(200)));
                Notification::assertSentTo(
                    $actor,
                    MaintenanceAlertNotification::class,
                    static fn (MaintenanceAlertNotification $notification): bool => $notification->toArray($actor)['kind']
                        === 'incident_escalated',
                );
                $recoveryEvent = (string) Str::ulid();
                $faults->observe(
                    $recoveryEvent,
                    (string) $site->getKey(),
                    'connector',
                    $connectorId,
                    'available',
                    null,
                    CarbonImmutable::now('UTC')->addSeconds(180),
                );
                self::assertSame(0, $faults->validateRecoveries(CarbonImmutable::now('UTC')->addSeconds(220)));
                self::assertSame(1, $faults->validateRecoveries(CarbonImmutable::now('UTC')->addSeconds(241)));
                self::assertSame(IncidentState::Resolved, $incident->refresh()->state);

                $faults->observe(
                    $recoveryEvent,
                    (string) $site->getKey(),
                    'connector',
                    $connectorId,
                    'available',
                    null,
                    CarbonImmutable::now('UTC')->addSeconds(300),
                );
                self::assertSame(3, $incident->observations()->count());
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_only_policy_approved_hold_states_pause_and_extend_the_sla(): void
    {
        CarbonImmutable::setTestNow('2026-07-28T01:30:00Z');

        try {
            $tenant = $this->createTenant('maintenance-sla-pauses');
            $actor = $this->createUser();
            $this->withinTenant($tenant, $actor, function (): void {
                [$site, $station] = $this->assetFixture();
                $priority = MaintenancePriority::factory()->create();
                $sla = SlaPolicy::factory()->create([
                    'priority_id' => $priority->getKey(),
                    'resolve_seconds' => 300,
                    'pause_states' => [WorkOrderState::AwaitingAccess->value],
                ]);
                $workflow = app(WorkOrderWorkflow::class);
                $workOrder = $workflow->create([
                    'work_type' => 'corrective',
                    'site_id' => (string) $site->getKey(),
                    'asset_type' => 'station',
                    'asset_id' => (string) $station->getKey(),
                    'priority_id' => (string) $priority->getKey(),
                    'sla_policy_id' => (string) $sla->getKey(),
                    'title' => 'Policy pause evidence',
                    'description' => 'Validate explicit SLA pause-state behavior.',
                ]);
                $originalResolveTarget = $workOrder->resolve_target_at;
                self::assertNotNull($originalResolveTarget);

                $workflow->transition($workOrder->refresh(), WorkOrderState::Triaged, 'acknowledged');
                $workflow->plan($workOrder->refresh(), 'Inspect the station.', null, null, 'UTC');
                $workflow->placeOnHold(
                    $workOrder->refresh(),
                    WorkOrderState::AwaitingParts,
                    'parts_pending',
                    'This state is not configured to pause the SLA.',
                );
                self::assertNull($workOrder->refresh()->sla_paused_at);
                $workflow->transition($workOrder->refresh(), WorkOrderState::Planned, 'parts_available');
                $workflow->plan(
                    $workOrder->refresh(),
                    'Inspect the station.',
                    CarbonImmutable::now('UTC')->addHour(),
                    CarbonImmutable::now('UTC')->addHours(2),
                    'UTC',
                );
                $workflow->placeOnHold(
                    $workOrder->refresh(),
                    WorkOrderState::AwaitingAccess,
                    'site_access_pending',
                    'This state is configured to pause the SLA.',
                );
                self::assertNotNull($workOrder->refresh()->sla_paused_at);

                CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(120));
                $workflow->transition($workOrder->refresh(), WorkOrderState::Scheduled, 'site_access_granted');
                self::assertSame(120, $workOrder->refresh()->sla_paused_seconds);
                self::assertTrue($workOrder->resolve_target_at?->equalTo($originalResolveTarget->addSeconds(120)));
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_preventive_scheduler_supports_all_four_trigger_types_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-07-28T03:00:00Z');

        try {
            $tenant = $this->createTenant('maintenance-preventive');
            $actor = $this->createUser();
            $this->withinTenant($tenant, $actor, function (): void {
                [$site, $station] = $this->assetFixture();
                $priority = MaintenancePriority::factory()->create();
                $this->assetMasterLists();
                ChargingSession::factory()->count(2)->create([
                    'site_id' => $site->getKey(),
                    'charging_station_id' => $station->getKey(),
                    'duration_seconds' => 60,
                    'energy_wh' => 600,
                    'started_at' => now('UTC')->subHour(),
                ]);
                foreach ([
                    [PreventiveTriggerType::Date, 86400, now('UTC')->subMinute()],
                    [PreventiveTriggerType::RuntimeSeconds, 100, null],
                    [PreventiveTriggerType::SessionCount, 2, null],
                    [PreventiveTriggerType::EnergyWh, 1000, null],
                ] as [$type, $interval, $nextDue]) {
                    PreventivePlan::query()->create([
                        'plan_number' => 'PM-'.Str::ulid(),
                        'site_id' => $site->getKey(),
                        'asset_type' => 'station',
                        'asset_id' => $station->getKey(),
                        'name' => Str::headline($type->value).' maintenance',
                        'trigger_type' => $type,
                        'interval_value' => $interval,
                        'next_due_at' => $nextDue,
                        'priority_id' => $priority->getKey(),
                        'is_active' => true,
                    ]);
                }
                $scheduler = app(PreventiveMaintenanceScheduler::class);
                self::assertSame(4, $scheduler->run(CarbonImmutable::now('UTC')));
                self::assertSame(4, PreventiveOccurrence::query()->count());
                self::assertSame(4, WorkOrder::query()->where('work_type', 'preventive')->count());
                self::assertSame(0, $scheduler->run(CarbonImmutable::now('UTC')));
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_work_order_parts_use_inventory_reservations_and_immutable_movements(): void
    {
        $tenant = $this->createTenant('maintenance-parts');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$site, $station] = $this->assetFixture();
            $priority = MaintenancePriority::factory()->create();
            $workOrder = app(WorkOrderWorkflow::class)->create([
                'work_type' => 'corrective',
                'site_id' => (string) $site->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $station->getKey(),
                'priority_id' => (string) $priority->getKey(),
                'title' => 'Replace a damaged component',
                'description' => 'Parts integration test.',
            ]);
            $stock = $this->inventoryFixture();
            app(StockLedger::class)->post([
                'item_id' => (string) $stock['item']->getKey(),
                'uom_id' => (string) $stock['uom']->getKey(),
                'to_bin_id' => (string) $stock['bin']->getKey(),
                'movement_type' => MovementType::Receipt,
                'quantity_base' => 10,
                'currency' => 'PHP',
                'unit_cost_minor' => 100,
                'reference_type' => 'opening_balance',
                'reference_id' => (string) Str::ulid(),
                'reason_code' => 'test_stock',
                'idempotency_key' => 'maintenance-opening-stock',
            ]);
            $parts = app(MaintenancePartService::class);
            $requirement = $parts->require(
                $workOrder,
                (string) $stock['item']->getKey(),
                (string) $stock['warehouse']->getKey(),
                5,
                (string) $stock['bin']->getKey(),
            );
            $parts->reserve($requirement, 'maintenance-reserve');
            $parts->issue($requirement->refresh(), (string) $stock['bin']->getKey(), 5, 'maintenance-issue');
            try {
                $parts->returnUnused(
                    $requirement->refresh(),
                    (string) $stock['uom']->getKey(),
                    (string) $stock['bin']->getKey(),
                    1,
                    100,
                    'USD',
                    'maintenance-foreign-currency-return',
                );
                self::fail('A foreign-currency part return entered the work-order rollup.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'Returned part cost must use the work-order currency.',
                    $exception->getMessage(),
                );
            }
            $parts->returnUnused(
                $requirement->refresh(),
                (string) $stock['uom']->getKey(),
                (string) $stock['bin']->getKey(),
                2,
                100,
                'PHP',
                'maintenance-return',
            );
            self::assertSame(5, $requirement->refresh()->issued_quantity_base);
            self::assertSame(2, $requirement->returned_quantity_base);
            self::assertSame(300, $workOrder->refresh()->actual_cost_minor);
            self::assertSame(1, StockMovement::query()->where('movement_type', MovementType::WorkOrderIssue)->count());
            self::assertSame(1, StockMovement::query()->where('movement_type', MovementType::WorkOrderReturn)->count());
        });
    }

    public function test_service_intake_evidence_inspection_warranty_rma_and_vendor_repair_are_guarded(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $tenant = $this->createTenant('maintenance-evidence');
        $dispatcher = $this->createUser();
        $verifier = $this->createUser();

        [$workOrder, $inspection] = $this->withinTenant($tenant, $dispatcher, function (): array {
            [$site, $station] = $this->assetFixture();
            $priority = MaintenancePriority::factory()->create();
            $vendor = Organization::factory()->create(['type' => OrganizationType::Vendor]);
            $evidence = app(MaintenanceEvidenceService::class);
            $warranty = $evidence->createWarranty(
                (string) $site->getKey(),
                'station',
                (string) $station->getKey(),
                'LOCAL-WARRANTY-REFERENCE',
                CarbonImmutable::now('UTC')->subMonth(),
                CarbonImmutable::now('UTC')->addYear(),
                (string) $vendor->getKey(),
                'Local test coverage terms only.',
            );
            $workOrder = app(WorkOrderWorkflow::class)->create([
                'work_type' => 'corrective',
                'site_id' => (string) $site->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $station->getKey(),
                'priority_id' => (string) $priority->getKey(),
                'warranty_id' => (string) $warranty->getKey(),
                'title' => 'Warranty evidence test',
                'description' => 'Validate maintenance evidence ownership and currency.',
            ]);
            $attachment = $evidence->storeAttachment(
                $workOrder,
                UploadedFile::fake()->image('before-repair.jpg', 640, 480),
                'photo',
            );
            Storage::disk('local')->assertExists($attachment->path);
            self::assertSame('pending', $attachment->scan_status);

            $inspection = $evidence->inspect($workOrder, 'return_to_service', 'pass', 'Electrical checks passed.');
            try {
                $evidence->approveInspection($inspection);
                self::fail('The inspector approved their own evidence.');
            } catch (DomainException $exception) {
                self::assertSame('Inspection approval requires a different user.', $exception->getMessage());
            }
            $rma = $evidence->openRma(
                $workOrder,
                (string) $vendor->getKey(),
                'RMA-LOCAL-001',
                'Covered component failed.',
                1000,
                'PHP',
            );
            $evidence->recordRmaOutcome($rma, 'recovered', 800, 600);
            $evidence->recordVendorRepair(
                $workOrder,
                (string) $vendor->getKey(),
                900,
                'PHP',
                'VENDOR-REPAIR-LOCAL',
                (string) $rma->getKey(),
            );
            self::assertSame(900, $workOrder->refresh()->actual_cost_minor);

            $serviceRequest = app(MaintenanceIntakeService::class)->submitServiceRequest([
                'site_id' => (string) $site->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $station->getKey(),
                'title' => 'Driver reported intermittent charging',
                'description' => 'Reproduce and inspect under load.',
            ]);
            $converted = app(MaintenanceIntakeService::class)->convert(
                $serviceRequest,
                (string) $priority->getKey(),
            );
            self::assertSame('converted', $converted->status);
            self::assertNotNull($converted->converted_incident_id);
            self::assertNotNull($converted->converted_work_order_id);

            return [$workOrder, $inspection];
        });

        $this->withinTenant($tenant, $verifier, function () use ($inspection, $tenant, $verifier): void {
            $approved = app(MaintenanceEvidenceService::class)->approveInspection($inspection->refresh());
            self::assertSame($this->tenantContext($tenant, $verifier)->actorId, $approved->approved_by);
        });
        self::assertSame(900, $workOrder->actual_cost_minor);
    }

    public function test_api_and_queries_intersect_tenant_and_site_scope(): void
    {
        $tenant = $this->createTenant('maintenance-api');
        $user = $this->createUser();
        [$siteA, $siteB, $workA, $workB] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-maintenance-reader', [PermissionKey::MaintenanceView]);
            [$siteA, $stationA] = $this->assetFixture();
            [$siteB, $stationB] = $this->assetFixture();
            $this->assignDirectly(
                $tenant,
                $membership,
                $role,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );
            $priority = MaintenancePriority::factory()->create();
            $workflow = app(WorkOrderWorkflow::class);
            $workA = $workflow->create([
                'work_type' => 'corrective',
                'site_id' => (string) $siteA->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $stationA->getKey(),
                'priority_id' => (string) $priority->getKey(),
                'title' => 'Authorized site work',
                'description' => 'Visible.',
            ]);
            $workB = $workflow->create([
                'work_type' => 'corrective',
                'site_id' => (string) $siteB->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $stationB->getKey(),
                'priority_id' => (string) $priority->getKey(),
                'title' => 'Sibling site work',
                'description' => 'Hidden.',
            ]);

            return [$siteA, $siteB, $workA, $workB];
        });
        $token = $this->login($user, $tenant);
        $response = $this->withToken($token)->getJson('/api/v1/maintenance/work-orders')
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $workA->getKey()])
            ->assertJsonMissing(['id' => (string) $workB->getKey()]);
        self::assertCount(1, $response->json('data'));
        $this->withToken($token)->getJson('/api/v1/maintenance/work-orders/'.$workB->getKey())
            ->assertForbidden();

        $otherTenant = $this->createTenant('maintenance-api-other');
        $otherWork = $this->withinTenant($otherTenant, $user, function (): WorkOrder {
            [$site, $station] = $this->assetFixture();

            return app(WorkOrderWorkflow::class)->create([
                'work_type' => 'corrective',
                'site_id' => (string) $site->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $station->getKey(),
                'priority_id' => (string) MaintenancePriority::factory()->create()->getKey(),
                'title' => 'Other tenant work',
                'description' => 'Never visible.',
            ]);
        });
        $this->withToken($token)->getJson('/api/v1/maintenance/work-orders/'.$otherWork->getKey())
            ->assertNotFound();
        self::assertNotSame($siteA->getKey(), $siteB->getKey());
    }

    public function test_phone_ready_technician_workboard_lists_only_the_users_active_assignments(): void
    {
        Notification::fake();
        $tenant = $this->createTenant('maintenance-technician-browser');
        $technician = $this->createUser();
        [$site, $workOrder] = $this->withinTenant($tenant, $technician, function () use ($tenant, $technician): array {
            $membership = $this->createMembership($tenant, $technician);
            $role = $this->createRole($tenant, 'technician-browser', [
                PermissionKey::OperatorPanelAccess,
                PermissionKey::MaintenancePerform,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
            [$site, $station] = $this->assetFixture();
            $workflow = app(WorkOrderWorkflow::class);
            $workOrder = $workflow->create([
                'work_type' => 'corrective',
                'site_id' => (string) $site->getKey(),
                'asset_type' => 'station',
                'asset_id' => (string) $station->getKey(),
                'priority_id' => (string) MaintenancePriority::factory()->create()->getKey(),
                'title' => 'Inspect the DC contactor',
                'description' => 'Phone workboard rendering test.',
            ]);
            $workflow->transition($workOrder, WorkOrderState::Triaged, 'dispatch_acknowledged');
            $workflow->plan($workOrder->refresh(), 'Inspect and record evidence.', null, null, 'UTC');
            $workflow->assign($workOrder->refresh(), (string) $technician->public_id, null);

            return [$site, $workOrder];
        });

        $this->actingAs($technician)->get('/operator/technician-workboard')
            ->assertOk()
            ->assertSee('Today’s assigned work')
            ->assertSee($workOrder->work_order_number)
            ->assertSee('Inspect the DC contactor')
            ->assertSee($site->name)
            ->assertSee('Start work');
    }

    /** @return array{Site, ChargingStation} */
    private function assetFixture(): array
    {
        $site = Site::factory()->create();
        $station = ChargingStation::factory()->create([
            'site_id' => $site->getKey(),
            'lifecycle_status' => AssetLifecycleStatus::Active,
        ]);

        return [$site, $station];
    }

    /** @return array{Site, ChargingStation, string} */
    private function connectorFixture(): array
    {
        [$site, $station] = $this->assetFixture();
        $this->assetMasterLists();
        $connector = Connector::factory()->create();
        $connector->evse->forceFill(['charging_station_id' => $station->getKey()])->save();

        return [$site, $station, (string) $connector->getKey()];
    }

    private function assetMasterLists(): void
    {
        ConnectorStandard::query()->firstOrCreate(['code' => 'ccs2'], ['name' => 'CCS2']);
        ChargingCurrentType::query()->firstOrCreate(['code' => 'dc'], ['name' => 'DC']);
    }

    /**
     * @return array{warehouse:Warehouse,bin:InventoryBin,item:InventoryItem,uom:UnitOfMeasure}
     */
    private function inventoryFixture(): array
    {
        $uom = UnitOfMeasure::factory()->create();
        $category = ItemCategory::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $location = StockLocation::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'custody_type' => 'available',
        ]);
        $bin = InventoryBin::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_location_id' => $location->getKey(),
        ]);
        $item = InventoryItem::factory()->create([
            'category_id' => $category->getKey(),
            'base_uom_id' => $uom->getKey(),
            'currency' => 'PHP',
        ]);

        return compact('warehouse', 'bin', 'item', 'uom');
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Maintenance tests',
        ])->assertOk()->json('data.token');
    }
}
