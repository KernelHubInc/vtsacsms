<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Organizations\Domain\PermissionKey;
use Carbon\CarbonImmutable;

final readonly class MaintenanceAutomationService
{
    public function __construct(
        private PreventiveMaintenanceScheduler $preventive,
        private FaultAutomationService $faults,
        private MaintenanceNotifier $notifier,
    ) {}

    /** @return array{preventive:int,escalated:int,recovered:int,sla_breaches:int} */
    public function run(CarbonImmutable $asOf): array
    {
        return [
            'preventive' => $this->preventive->run($asOf),
            'escalated' => $this->faults->escalatePersistent($asOf),
            'recovered' => $this->faults->validateRecoveries($asOf),
            'sla_breaches' => $this->notifySlaBreaches($asOf),
        ];
    }

    private function notifySlaBreaches(CarbonImmutable $asOf): int
    {
        $count = 0;
        $terminal = [WorkOrderState::Closed->value, WorkOrderState::Canceled->value];
        $orders = WorkOrder::query()
            ->whereNotIn('state', $terminal)
            ->where(fn ($query) => $query
                ->where(function ($query) use ($asOf): void {
                    $query->whereNull('acknowledged_at')
                        ->whereNull('acknowledge_breach_notified_at')
                        ->whereNotNull('acknowledge_target_at')
                        ->where('acknowledge_target_at', '<', $asOf);
                })
                ->orWhere(function ($query) use ($asOf): void {
                    $query->whereNull('resolve_breach_notified_at')
                        ->whereNotNull('resolve_target_at')
                        ->where('resolve_target_at', '<', $asOf);
                }))
            ->get();
        foreach ($orders as $order) {
            if ($order->sla_paused_at !== null) {
                continue;
            }
            $kinds = [];
            if ($order->acknowledged_at === null
                && $order->acknowledge_breach_notified_at === null
                && $order->acknowledge_target_at?->isBefore($asOf)) {
                $order->forceFill(['acknowledge_breach_notified_at' => $asOf])->save();
                $kinds[] = 'acknowledgement';
            }
            if ($order->resolve_breach_notified_at === null && $order->resolve_target_at?->isBefore($asOf)) {
                $order->forceFill(['resolve_breach_notified_at' => $asOf])->save();
                $kinds[] = 'resolution';
            }
            if ($kinds === []) {
                continue;
            }
            $this->notifier->sitePermission(
                (string) $order->site_id,
                PermissionKey::MaintenanceDispatch,
                'maintenance_sla_breached',
                'Maintenance SLA breached',
                "Work order {$order->work_order_number} breached its ".implode(' and ', $kinds).' target.',
                ['work_order_id' => (string) $order->getKey(), 'site_id' => (string) $order->site_id],
            );
            $count++;
        }

        return $count;
    }
}
