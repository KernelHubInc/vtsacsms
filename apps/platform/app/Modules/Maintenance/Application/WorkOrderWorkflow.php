<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Maintenance\Domain\Models\AssetAction;
use App\Modules\Maintenance\Domain\Models\ChecklistTemplateItem;
use App\Modules\Maintenance\Domain\Models\DowntimePeriod;
use App\Modules\Maintenance\Domain\Models\MaintenanceTimeEntry;
use App\Modules\Maintenance\Domain\Models\SlaPolicy;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\Models\WorkOrderAssignment;
use App\Modules\Maintenance\Domain\Models\WorkOrderChecklistItem;
use App\Modules\Maintenance\Domain\Models\WorkOrderTransition;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WorkOrderWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private AssetMaintenanceContract $assets,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
        private MaintenanceNotifier $notifier,
    ) {}

    /**
     * @param array{
     *   work_type:string, site_id:string, asset_type:string, asset_id:string, priority_id:string,
     *   title:string, description:string, currency?:string, sla_policy_id?:string|null,
     *   checklist_template_id?:string|null, service_request_id?:string|null,
     *   preventive_plan_id?:string|null, warranty_id?:string|null, safety_critical?:bool,
     *   verification_required?:bool, asset_restriction_required?:bool, estimated_cost_minor?:int,
     *   scope?:string|null, reopened_from_id?:string|null
     * } $data
     */
    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data): WorkOrder {
            $actorId = $this->tenant->get()->actorId;
            $this->assets->assertBelongsToSite($data['asset_type'], $data['asset_id'], $data['site_id']);
            $sla = $this->effectiveSla($data['sla_policy_id'] ?? null, $data['priority_id']);
            $currency = $this->currency($data['currency'] ?? 'PHP');
            $now = CarbonImmutable::now('UTC');
            $workOrder = WorkOrder::query()->create([
                ...$data,
                'work_order_number' => 'WO-'.Str::ulid(),
                'sla_policy_id' => $sla?->getKey(),
                'state' => WorkOrderState::Reported,
                'created_by' => $actorId,
                'currency' => $currency,
                'estimated_cost_minor' => max(0, $data['estimated_cost_minor'] ?? 0),
                'actual_cost_minor' => 0,
                'acknowledge_target_at' => $sla === null
                    ? null
                    : $now->addSeconds((int) $sla->acknowledge_seconds),
                'resolve_target_at' => $sla === null
                    ? null
                    : $now->addSeconds((int) $sla->resolve_seconds),
                'aggregate_version' => 1,
            ]);
            $this->copyChecklist($workOrder);
            $this->recordTransition($workOrder, null, WorkOrderState::Reported, 'work_order_created', null, 1);
            $this->outbox->record('maintenance.work_order.created.v1', 'maintenance_work_order', (string) $workOrder->getKey(), [
                'work_order_number' => $workOrder->work_order_number,
                'site_id' => $workOrder->site_id,
                'asset_type' => $workOrder->asset_type,
                'asset_id' => $workOrder->asset_id,
                'priority_id' => $workOrder->priority_id,
            ]);
            $this->audit->record(new AuditEntry(
                'maintenance.work_order.created',
                'maintenance_work_order',
                (string) $workOrder->getKey(),
                AuditResult::Succeeded,
                after: $this->auditSnapshot($workOrder),
            ));

            return $workOrder->load(['checklistItems', 'transitions']);
        });
    }

    public function transition(
        WorkOrder $workOrder,
        WorkOrderState $target,
        string $reasonCode,
        ?string $notes = null,
    ): WorkOrder {
        return DB::transaction(function () use ($workOrder, $target, $reasonCode, $notes): WorkOrder {
            $locked = WorkOrder::query()->whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->state;
            if (! $from->canTransitionTo($target)) {
                throw new DomainException("Work order cannot move from {$from->value} to {$target->value}.");
            }
            $this->guardTransition($locked, $target);
            $before = $this->auditSnapshot($locked);
            $now = CarbonImmutable::now('UTC');
            $changes = ['state' => $target];

            if ($target === WorkOrderState::Triaged && $locked->acknowledged_at === null) {
                $changes['acknowledged_at'] = $now;
                $changes['acknowledged_by'] = $this->actorId();
            }
            if ($target === WorkOrderState::InProgress && $locked->started_at === null) {
                $changes['started_at'] = $now;
            }
            if ($target === WorkOrderState::Completed) {
                $changes['completed_at'] = $now;
                $changes['completed_by'] = $this->actorId();
            }
            if ($target === WorkOrderState::Verified) {
                $changes['verified_at'] = $now;
                $changes['verified_by'] = $this->actorId();
            }
            if ($target === WorkOrderState::Closed) {
                $changes['closed_at'] = $now;
                $changes['closed_by'] = $this->actorId();
            }
            if (! $target->pausesSla()) {
                $changes['hold_reason_code'] = null;
                $changes['hold_notes'] = null;
                $changes['hold_review_at'] = null;
            }
            $targetPausesSla = $this->policyPausesSla($locked, $target);
            if ($targetPausesSla && $locked->sla_paused_at === null) {
                $changes['sla_paused_at'] = $now;
            } elseif (! $targetPausesSla && $locked->sla_paused_at !== null) {
                $pauseSeconds = max(0, (int) $locked->sla_paused_at->diffInSeconds($now));
                $changes['sla_paused_at'] = null;
                $changes['sla_paused_seconds'] = $locked->sla_paused_seconds + $pauseSeconds;
                $changes['acknowledge_target_at'] = $locked->acknowledged_at === null
                    ? $locked->acknowledge_target_at?->addSeconds($pauseSeconds)
                    : $locked->acknowledge_target_at;
                $changes['resolve_target_at'] = $locked->resolve_target_at?->addSeconds($pauseSeconds);
            }
            $version = $locked->aggregate_version + 1;
            $changes['aggregate_version'] = $version;

            if ($target === WorkOrderState::InProgress && $locked->asset_restriction_required) {
                $this->assets->restrictForMaintenance(
                    (string) $locked->asset_type,
                    (string) $locked->asset_id,
                    (string) $locked->getKey(),
                    $reasonCode,
                );
                $this->recordAssetAction($locked, 'restrict', $reasonCode);
                $this->startDowntime($locked, $reasonCode, $now);
            }
            if (in_array($target, [WorkOrderState::Verified, WorkOrderState::Closed], true)
                && $locked->asset_restriction_required) {
                $this->assets->returnToService(
                    (string) $locked->asset_type,
                    (string) $locked->asset_id,
                    (string) $locked->getKey(),
                    $reasonCode,
                );
                $this->recordAssetAction($locked, 'return_to_service', $reasonCode);
                $this->endDowntime($locked, $now);
            }

            $locked->forceFill($changes)->save();
            $this->recordTransition($locked, $from, $target, $reasonCode, $notes, $version);
            $this->outbox->record(
                $this->transitionEvent($target),
                'maintenance_work_order',
                (string) $locked->getKey(),
                [
                    'from_state' => $from->value,
                    'to_state' => $target->value,
                    'reason_code' => $reasonCode,
                    'site_id' => (string) $locked->site_id,
                    'asset_type' => (string) $locked->asset_type,
                    'asset_id' => (string) $locked->asset_id,
                    'started_at' => $locked->started_at?->toIso8601String(),
                    'completed_at' => $locked->completed_at?->toIso8601String(),
                    'verified_at' => $locked->verified_at?->toIso8601String(),
                    'closed_at' => $locked->closed_at?->toIso8601String(),
                ],
            );
            $this->audit->record(new AuditEntry(
                'maintenance.work_order.transitioned',
                'maintenance_work_order',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reasonCode,
                before: $before,
                after: $this->auditSnapshot($locked),
            ));
            if ($target === WorkOrderState::VerificationRequired) {
                $this->notifier->sitePermission(
                    (string) $locked->site_id,
                    PermissionKey::MaintenanceVerify,
                    'verification_required',
                    'Maintenance verification required',
                    "Work order {$locked->work_order_number} is ready for independent verification.",
                    ['work_order_id' => (string) $locked->getKey(), 'site_id' => (string) $locked->site_id],
                );
            }

            return $locked->load(['transitions', 'checklistItems', 'assignments']);
        });
    }

    public function assign(
        WorkOrder $workOrder,
        ?string $technicianPublicId,
        ?string $vendorOrganizationId,
    ): WorkOrderAssignment {
        if (($technicianPublicId === null) === ($vendorOrganizationId === null)) {
            throw new DomainException('Assign exactly one technician or service vendor.');
        }

        return DB::transaction(function () use ($workOrder, $technicianPublicId, $vendorOrganizationId): WorkOrderAssignment {
            $locked = WorkOrder::query()->whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->state, [
                WorkOrderState::Triaged,
                WorkOrderState::Planned,
                WorkOrderState::Scheduled,
                WorkOrderState::Assigned,
            ], true)) {
                throw new DomainException('This work order cannot be assigned in its current state.');
            }
            $technicianUserId = null;
            if ($technicianPublicId !== null) {
                $technicianUserId = (string) User::query()
                    ->where('public_id', $technicianPublicId)
                    ->firstOrFail()
                    ->getKey();
                $this->assertTechnicianSkills($locked, $technicianUserId);
            } else {
                Organization::query()->whereKey($vendorOrganizationId)->firstOrFail();
            }
            WorkOrderAssignment::query()->where('work_order_id', $locked->getKey())
                ->where('status', 'assigned')->update(['status' => 'released', 'released_at' => now('UTC')]);
            $assignment = WorkOrderAssignment::query()->create([
                'work_order_id' => $locked->getKey(),
                'technician_user_id' => $technicianUserId,
                'vendor_organization_id' => $vendorOrganizationId,
                'status' => 'assigned',
                'assigned_by' => $this->actorId(),
                'assigned_at' => now('UTC'),
            ]);
            if ($locked->state !== WorkOrderState::Assigned) {
                $this->transition($locked, WorkOrderState::Assigned, 'resource_assigned');
            }
            if ($technicianUserId !== null) {
                $this->notifier->user(
                    $technicianUserId,
                    'work_order_assigned',
                    'Maintenance work assigned',
                    "Work order {$locked->work_order_number} has been assigned to you.",
                    ['work_order_id' => (string) $locked->getKey(), 'site_id' => (string) $locked->site_id],
                );
            }
            $this->outbox->record('maintenance.work_order.assigned.v1', 'maintenance_work_order', (string) $locked->getKey(), [
                'assignment_id' => (string) $assignment->getKey(),
                'technician_public_id' => $technicianPublicId,
                'vendor_organization_id' => $vendorOrganizationId,
            ]);

            return $assignment;
        });
    }

    public function plan(
        WorkOrder $workOrder,
        string $scope,
        ?CarbonImmutable $scheduledStartAt,
        ?CarbonImmutable $scheduledEndAt,
        string $timezone,
    ): WorkOrder {
        if ($scheduledStartAt !== null && $scheduledEndAt !== null && $scheduledEndAt->isBefore($scheduledStartAt)) {
            throw new DomainException('Scheduled completion cannot precede the scheduled start.');
        }
        $workOrder->forceFill([
            'scope' => $scope,
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
            'schedule_timezone' => $timezone,
        ])->save();
        $target = $scheduledStartAt === null ? WorkOrderState::Planned : WorkOrderState::Scheduled;

        return $this->transition($workOrder, $target, 'work_planned');
    }

    public function recordResolution(
        WorkOrder $workOrder,
        string $workPerformed,
        string $resolutionCodeId,
        ?string $failureCodeId = null,
        ?string $rootCauseCodeId = null,
        ?string $diagnosis = null,
    ): WorkOrder {
        if (! in_array($workOrder->state, [
            WorkOrderState::InProgress,
            WorkOrderState::AwaitingSafetyClearance,
        ], true)) {
            throw new DomainException('Resolution evidence may only be recorded while work is active.');
        }
        $workOrder->forceFill([
            'work_performed' => $workPerformed,
            'resolution_code_id' => $resolutionCodeId,
            'failure_code_id' => $failureCodeId,
            'root_cause_code_id' => $rootCauseCodeId,
            'diagnosis' => $diagnosis,
        ])->save();

        return $workOrder;
    }

    public function placeOnHold(
        WorkOrder $workOrder,
        WorkOrderState $holdState,
        string $reasonCode,
        string $notes,
        ?CarbonImmutable $reviewAt = null,
    ): WorkOrder {
        if (! $holdState->pausesSla()) {
            throw new DomainException('The selected state is not an approved hold state.');
        }
        $workOrder->forceFill([
            'hold_reason_code' => $reasonCode,
            'hold_notes' => $notes,
            'hold_review_at' => $reviewAt,
        ])->save();

        return $this->transition($workOrder, $holdState, $reasonCode, $notes);
    }

    public function completeChecklistItem(
        WorkOrderChecklistItem $item,
        string $result,
        ?string $notes = null,
    ): WorkOrderChecklistItem {
        if (! in_array($result, ['pass', 'fail', 'complete', 'not_applicable'], true)) {
            throw new DomainException('Unsupported checklist result.');
        }
        if ($item->requires_pass && $result !== 'pass') {
            throw new DomainException('This safety step requires a passing result.');
        }
        if ($item->requires_photo && ! $item->workOrder()->whereHas(
            'attachments',
            fn ($query) => $query->where('kind', 'photo'),
        )->exists()) {
            throw new DomainException('Required photo evidence must be uploaded first.');
        }
        $item->forceFill([
            'result' => $result,
            'notes' => $notes,
            'completed_by' => $this->actorId(),
            'completed_at' => now('UTC'),
        ])->save();

        return $item;
    }

    public function recordTime(
        WorkOrder $workOrder,
        string $type,
        int $durationSeconds,
        int $rateMinorPerHour,
        string $currency,
        ?CarbonImmutable $startedAt = null,
        ?string $notes = null,
    ): MaintenanceTimeEntry {
        if (! in_array($type, ['labor', 'travel'], true) || $durationSeconds <= 0 || $rateMinorPerHour < 0) {
            throw new DomainException('Time entries require labor or travel, positive seconds, and a non-negative rate.');
        }
        $currency = $this->currency($currency);
        if ($currency !== $workOrder->currency) {
            throw new DomainException('Maintenance cost entries must use the work-order currency.');
        }
        $total = (int) round(($durationSeconds * $rateMinorPerHour) / 3600);
        $entry = MaintenanceTimeEntry::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'user_id' => $this->userKey(),
            'type' => $type,
            'duration_seconds' => $durationSeconds,
            'rate_minor_per_hour' => $rateMinorPerHour,
            'total_minor' => $total,
            'currency' => $currency,
            'started_at' => $startedAt,
            'notes' => $notes,
        ]);
        $this->recalculateCost($workOrder);

        return $entry;
    }

    public function recalculateCost(WorkOrder $workOrder): WorkOrder
    {
        $time = (int) MaintenanceTimeEntry::query()->where('work_order_id', $workOrder->getKey())->sum('total_minor');
        $vendor = (int) DB::table('maintenance_vendor_repairs')
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('work_order_id', $workOrder->getKey())
            ->sum('cost_minor');
        $parts = (int) DB::table('inventory_stock_movements')
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('reference_type', 'maintenance_work_order')
            ->where('reference_id', $workOrder->getKey())
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'work_order_issue' THEN total_cost_minor WHEN movement_type = 'work_order_return' THEN -total_cost_minor ELSE 0 END), 0) AS total")
            ->value('total');
        $workOrder->forceFill(['actual_cost_minor' => max(0, $time + $vendor + $parts)])->save();

        return $workOrder;
    }

    public function reopen(WorkOrder $closed, string $reason): WorkOrder
    {
        if ($closed->state !== WorkOrderState::Closed) {
            throw new DomainException('Only a closed work order can be reopened.');
        }

        $followUp = $this->create([
            'work_type' => (string) $closed->work_type,
            'site_id' => (string) $closed->site_id,
            'asset_type' => (string) $closed->asset_type,
            'asset_id' => (string) $closed->asset_id,
            'priority_id' => (string) $closed->priority_id,
            'sla_policy_id' => $closed->sla_policy_id === null ? null : (string) $closed->sla_policy_id,
            'checklist_template_id' => $closed->checklist_template_id === null
                ? null
                : (string) $closed->checklist_template_id,
            'warranty_id' => $closed->warranty_id === null ? null : (string) $closed->warranty_id,
            'title' => 'Reopened: '.(string) $closed->title,
            'description' => $reason,
            'scope' => $closed->scope,
            'currency' => (string) $closed->currency,
            'safety_critical' => (bool) $closed->safety_critical,
            'verification_required' => (bool) $closed->verification_required,
            'asset_restriction_required' => (bool) $closed->asset_restriction_required,
            'reopened_from_id' => (string) $closed->getKey(),
        ]);
        $this->outbox->record('maintenance.work_order.reopened.v1', 'maintenance_work_order', (string) $followUp->getKey(), [
            'prior_work_order_id' => (string) $closed->getKey(),
            'new_work_order_id' => (string) $followUp->getKey(),
            'reason' => $reason,
        ]);

        return $followUp;
    }

    private function guardTransition(WorkOrder $workOrder, WorkOrderState $target): void
    {
        if ($target === WorkOrderState::InProgress
            && ! $workOrder->assignments()->where('status', 'assigned')->exists()) {
            throw new DomainException('A technician or service vendor must be assigned before work starts.');
        }
        if ($target === WorkOrderState::Completed) {
            if ($workOrder->checklistItems()->where('is_required', true)->whereNull('completed_at')->exists()) {
                throw new DomainException('All required checklist and safety steps must be completed.');
            }
            if ($workOrder->checklistItems()->where('requires_pass', true)->where('result', '!=', 'pass')->exists()) {
                throw new DomainException('All required safety results must pass.');
            }
            if ($workOrder->partRequirements()
                ->whereColumn('returned_quantity_base', '>', 'issued_quantity_base')->exists()) {
                throw new DomainException('Part return quantities are inconsistent.');
            }
        }
        if ($target === WorkOrderState::Verified) {
            if ($workOrder->verification_required && $workOrder->completed_by === $this->actorId()) {
                throw new DomainException('Required verification must be performed by a different user.');
            }
            if ($workOrder->resolution_code_id === null || $workOrder->work_performed === null) {
                throw new DomainException('Resolution code and work-performed evidence are required for verification.');
            }
        }
        if ($target === WorkOrderState::Closed && $workOrder->verified_at === null) {
            throw new DomainException('Only verified work may be closed.');
        }
    }

    private function policyPausesSla(WorkOrder $workOrder, WorkOrderState $state): bool
    {
        if (! $state->pausesSla() || $workOrder->sla_policy_id === null) {
            return false;
        }

        $policy = SlaPolicy::query()->whereKey($workOrder->sla_policy_id)->first();

        return $policy !== null
            && in_array($state->value, $policy->pause_states ?? [], true);
    }

    private function currency(string $currency): string
    {
        $normalized = mb_strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw new DomainException('Maintenance currency must be a three-letter ISO 4217 code.');
        }

        return $normalized;
    }

    private function startDowntime(WorkOrder $workOrder, string $reasonCode, CarbonImmutable $startedAt): void
    {
        DowntimePeriod::query()->firstOrCreate(
            ['work_order_id' => $workOrder->getKey(), 'asset_type' => $workOrder->asset_type, 'asset_id' => $workOrder->asset_id],
            [
                'incident_id' => null,
                'site_id' => $workOrder->site_id,
                'reason_code' => $reasonCode,
                'started_at' => $startedAt,
            ],
        );
    }

    private function recordAssetAction(WorkOrder $workOrder, string $action, string $reasonCode): void
    {
        AssetAction::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'action' => $action,
            'asset_type' => $workOrder->asset_type,
            'asset_id' => $workOrder->asset_id,
            'status' => 'completed',
            'reason_code' => $reasonCode,
            'evidence' => ['aggregate_version' => $workOrder->aggregate_version],
            'requested_by' => $this->actorId(),
            'requested_at' => now('UTC'),
            'decided_at' => now('UTC'),
        ]);
    }

    private function endDowntime(WorkOrder $workOrder, CarbonImmutable $endedAt): void
    {
        $periods = DowntimePeriod::query()->where('work_order_id', $workOrder->getKey())
            ->whereNull('ended_at')->lockForUpdate()->get();
        foreach ($periods as $period) {
            $period->forceFill([
                'ended_at' => $endedAt,
                'duration_seconds' => max(0, (int) $period->started_at->diffInSeconds($endedAt)),
            ])->save();
        }
    }

    private function assertTechnicianSkills(WorkOrder $workOrder, string $userId): void
    {
        if (! DB::table('memberships')
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->exists()) {
            throw new DomainException('The selected technician is not an active tenant member.');
        }
        $required = DB::table('maintenance_work_order_required_skills')
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('work_order_id', $workOrder->getKey())
            ->pluck('skill_id');
        if ($required->isEmpty()) {
            return;
        }
        $qualified = DB::table('maintenance_technician_skills')
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('user_id', $userId)
            ->whereIn('skill_id', $required)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now('UTC')))
            ->distinct()
            ->count('skill_id');
        if ($qualified !== $required->count()) {
            throw new DomainException('The selected technician lacks a current required skill.');
        }
    }

    private function copyChecklist(WorkOrder $workOrder): void
    {
        if ($workOrder->checklist_template_id === null) {
            return;
        }
        $items = ChecklistTemplateItem::query()
            ->where('template_id', $workOrder->checklist_template_id)
            ->orderBy('sort_order')
            ->get();
        foreach ($items as $item) {
            WorkOrderChecklistItem::query()->create([
                'work_order_id' => $workOrder->getKey(),
                'template_item_id' => $item->getKey(),
                'sort_order' => $item->sort_order,
                'item_type' => $item->item_type,
                'label' => $item->label,
                'instructions' => $item->instructions,
                'is_required' => $item->is_required,
                'requires_photo' => $item->requires_photo,
                'requires_pass' => $item->requires_pass,
            ]);
        }
    }

    private function effectiveSla(?string $slaPolicyId, string $priorityId): ?SlaPolicy
    {
        if ($slaPolicyId === null) {
            return null;
        }
        $now = now('UTC');

        return SlaPolicy::query()
            ->whereKey($slaPolicyId)
            ->where('priority_id', $priorityId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->firstOrFail();
    }

    private function recordTransition(
        WorkOrder $workOrder,
        ?WorkOrderState $from,
        WorkOrderState $to,
        string $reasonCode,
        ?string $notes,
        int $version,
    ): void {
        $context = $this->tenant->get();
        WorkOrderTransition::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'reason_code' => $reasonCode,
            'notes' => $notes,
            'version' => $version,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'occurred_at' => now('UTC'),
        ]);
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(WorkOrder $workOrder): array
    {
        return [
            'state' => $workOrder->state->value,
            'site_id' => $workOrder->site_id,
            'asset_type' => $workOrder->asset_type,
            'asset_id' => $workOrder->asset_id,
            'priority_id' => $workOrder->priority_id,
            'aggregate_version' => $workOrder->aggregate_version,
        ];
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Maintenance changes require an accountable actor.');
    }

    private function transitionEvent(WorkOrderState $target): string
    {
        return match ($target) {
            WorkOrderState::InProgress => 'maintenance.work_order.started.v1',
            WorkOrderState::AwaitingParts => 'maintenance.work_order.awaiting_parts.v1',
            WorkOrderState::Completed => 'maintenance.work_order.completed.v1',
            WorkOrderState::Verified => 'maintenance.work_order.verified.v1',
            WorkOrderState::Closed => 'maintenance.work_order.closed.v1',
            default => 'maintenance.work_order.state_changed.v1',
        };
    }

    private function userKey(): int
    {
        return (int) User::query()->where('public_id', $this->actorId())->firstOrFail()->getKey();
    }
}
