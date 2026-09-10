<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Maintenance\Domain\Models\PreventiveOccurrence;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use App\Modules\Organizations\Domain\PermissionKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class PreventiveMaintenanceScheduler
{
    public function __construct(
        private WorkOrderWorkflow $workOrders,
        private MaintenanceNotifier $notifier,
    ) {}

    public function run(CarbonImmutable $asOf): int
    {
        $generated = 0;
        PreventivePlan::query()->where('is_active', true)->chunkById(100, function ($plans) use ($asOf, &$generated): void {
            foreach ($plans as $plan) {
                if ($this->generateIfDue($plan, $asOf)) {
                    $generated++;
                }
            }
        });

        return $generated;
    }

    public function generateIfDue(PreventivePlan $plan, CarbonImmutable $asOf): bool
    {
        return DB::transaction(function () use ($plan, $asOf): bool {
            $locked = PreventivePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->interval_value <= 0) {
                return false;
            }
            [$due, $checkpoint, $triggerValue, $dueAt] = $this->checkpoint($locked, $asOf);
            if (! $due || PreventiveOccurrence::query()
                ->where('plan_id', $locked->getKey())
                ->where('checkpoint_key', $checkpoint)
                ->exists()) {
                return false;
            }
            $workOrder = $this->workOrders->create([
                'work_type' => 'preventive',
                'site_id' => (string) $locked->site_id,
                'asset_type' => (string) $locked->asset_type,
                'asset_id' => (string) $locked->asset_id,
                'priority_id' => (string) $locked->priority_id,
                'sla_policy_id' => $locked->sla_policy_id === null ? null : (string) $locked->sla_policy_id,
                'checklist_template_id' => $locked->checklist_template_id === null
                    ? null
                    : (string) $locked->checklist_template_id,
                'preventive_plan_id' => (string) $locked->getKey(),
                'title' => (string) $locked->name,
                'description' => 'Generated from preventive maintenance plan '.$locked->plan_number.'.',
            ]);
            PreventiveOccurrence::query()->create([
                'plan_id' => $locked->getKey(),
                'work_order_id' => $workOrder->getKey(),
                'checkpoint_key' => $checkpoint,
                'trigger_type' => $locked->trigger_type->value,
                'trigger_value' => $triggerValue,
                'due_at' => $dueAt,
                'generated_at' => $asOf,
            ]);
            $changes = ['last_generated_at' => $asOf];
            match ($locked->trigger_type) {
                PreventiveTriggerType::Date => $changes['next_due_at'] = $asOf->addSeconds($locked->interval_value),
                PreventiveTriggerType::RuntimeSeconds => $changes['baseline_runtime_seconds'] = $triggerValue,
                PreventiveTriggerType::SessionCount => $changes['baseline_session_count'] = $triggerValue,
                PreventiveTriggerType::EnergyWh => $changes['baseline_energy_wh'] = $triggerValue,
            };
            $locked->forceFill($changes)->save();
            $this->notifier->sitePermission(
                (string) $locked->site_id,
                PermissionKey::MaintenanceDispatch,
                'preventive_maintenance_due',
                'Preventive maintenance due',
                "Plan {$locked->plan_number} generated work order {$workOrder->work_order_number}.",
                [
                    'plan_id' => (string) $locked->getKey(),
                    'work_order_id' => (string) $workOrder->getKey(),
                    'site_id' => (string) $locked->site_id,
                ],
            );

            return true;
        });
    }

    /**
     * @return array{bool, string, int|null, CarbonImmutable|null}
     */
    private function checkpoint(PreventivePlan $plan, CarbonImmutable $asOf): array
    {
        if ($plan->trigger_type === PreventiveTriggerType::Date) {
            if ($plan->next_due_at === null || $plan->next_due_at->isAfter($asOf)) {
                return [false, '', null, $plan->next_due_at];
            }

            return [true, 'date:'.$plan->next_due_at->format('YmdHis'), null, $plan->next_due_at];
        }
        if ($plan->asset_type === 'component') {
            return [false, '', null, null];
        }

        $usage = $this->usageQuery($plan);
        [$value, $baseline] = match ($plan->trigger_type) {
            PreventiveTriggerType::RuntimeSeconds => [
                (int) (clone $usage)->sum('duration_seconds'),
                $plan->baseline_runtime_seconds,
            ],
            PreventiveTriggerType::SessionCount => [
                (int) (clone $usage)->whereNotNull('started_at')->count(),
                $plan->baseline_session_count,
            ],
            PreventiveTriggerType::EnergyWh => [
                (int) (clone $usage)->sum('energy_wh'),
                $plan->baseline_energy_wh,
            ],
        };
        if (($value - $baseline) < $plan->interval_value) {
            return [false, '', $value, null];
        }

        return [true, $plan->trigger_type->value.':'.$value, $value, null];
    }

    private function usageQuery(PreventivePlan $plan): Builder
    {
        $query = DB::table('charging_sessions')->where('tenant_id', $plan->tenant_id);

        return match ($plan->asset_type) {
            'station' => $query->where('charging_station_id', $plan->asset_id),
            'evse' => $query->where('evse_id', $plan->asset_id),
            'connector' => $query->where('connector_id', $plan->asset_id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
