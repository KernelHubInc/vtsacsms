<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Modules\Maintenance\Application\WorkOrderWorkflow;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\Models\WorkOrderAssignment;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

final class TechnicianWorkboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?string $navigationLabel = 'My work';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.operator.pages.technician-workboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::MaintenancePerform);
    }

    public function startWork(string $workOrderId): void
    {
        $this->changeState($workOrderId, WorkOrderState::InProgress, 'technician_started');
    }

    public function resumeWork(string $workOrderId): void
    {
        $this->changeState($workOrderId, WorkOrderState::InProgress, 'technician_resumed');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'assignments' => WorkOrderAssignment::query()
                ->where('technician_user_id', $user->getKey())
                ->where('status', 'assigned')
                ->whereHas('workOrder', fn ($query) => $query->whereNotIn('state', ['closed', 'canceled']))
                ->with(['workOrder.site', 'workOrder.priority', 'workOrder.checklistItems'])
                ->latest('assigned_at')
                ->get(),
        ];
    }

    private function changeState(string $workOrderId, WorkOrderState $state, string $reason): void
    {
        try {
            $workOrder = WorkOrder::query()->whereKey($workOrderId)->firstOrFail();
            Gate::authorize('perform', $workOrder);
            app(WorkOrderWorkflow::class)->transition($workOrder, $state, $reason);
            Notification::make()->title('Work order updated')->success()->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Unable to update work order')
                ->body($exception->getMessage())->danger()->send();
        }
    }
}
