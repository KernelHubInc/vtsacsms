<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Domain\WorkOrderState;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Maintenance\Domain\Models\WorkOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property WorkOrderState $state
 * @property bool $safety_critical
 * @property bool $verification_required
 * @property bool $asset_restriction_required
 * @property int $estimated_cost_minor
 * @property int $actual_cost_minor
 * @property int $aggregate_version
 * @property CarbonImmutable|null $scheduled_start_at
 * @property CarbonImmutable|null $scheduled_end_at
 * @property CarbonImmutable|null $acknowledge_target_at
 * @property CarbonImmutable|null $resolve_target_at
 * @property CarbonImmutable|null $sla_paused_at
 * @property int $sla_paused_seconds
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $closed_at
 */
final class WorkOrder extends TenantMaintenanceModel
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    protected $table = 'maintenance_work_orders';

    protected function casts(): array
    {
        return [
            'state' => WorkOrderState::class,
            'safety_critical' => 'boolean',
            'verification_required' => 'boolean',
            'asset_restriction_required' => 'boolean',
            'estimated_cost_minor' => 'integer',
            'actual_cost_minor' => 'integer',
            'aggregate_version' => 'integer',
            'hold_review_at' => 'immutable_datetime',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'acknowledge_target_at' => 'immutable_datetime',
            'resolve_target_at' => 'immutable_datetime',
            'sla_paused_at' => 'immutable_datetime',
            'sla_paused_seconds' => 'integer',
            'acknowledge_breach_notified_at' => 'immutable_datetime',
            'resolve_breach_notified_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<WorkOrderTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkOrderTransition::class)->orderBy('version');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<MaintenancePriority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(MaintenancePriority::class, 'priority_id');
    }

    /** @return HasMany<WorkOrderAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    /** @return HasMany<WorkOrderChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(WorkOrderChecklistItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<PartRequirement, $this> */
    public function partRequirements(): HasMany
    {
        return $this->hasMany(PartRequirement::class);
    }

    /** @return HasMany<MaintenanceTimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(MaintenanceTimeEntry::class);
    }

    /** @return HasMany<MaintenanceAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MaintenanceAttachment::class);
    }

    /** @return BelongsToMany<Incident, $this> */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(
            Incident::class,
            'maintenance_work_order_incidents',
            'work_order_id',
            'incident_id',
        )->withTimestamps();
    }
}
