<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Http\Requests\Api\StoreMaintenanceWorkOrderRequest;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Maintenance\Application\AccessibleMaintenanceSitesQuery;
use App\Modules\Maintenance\Application\MaintenanceIntakeService;
use App\Modules\Maintenance\Application\PreventivePlanManager;
use App\Modules\Maintenance\Application\WorkOrderWorkflow;
use App\Modules\Maintenance\Domain\Models\MaintenancePriority;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Maintenance\Domain\PreventiveTriggerType;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintenanceActions
{
    public static function allowed(): bool
    {
        return auth()->user() instanceof User && app(AuthorizationService::class)->holdsAnywhere(auth()->user(), PermissionKey::MaintenanceDispatch);
    }

    /** @return list<Component> */
    private static function assetFields(bool $editing = false): array
    {
        return [
            Select::make('site_id')->label('Site')->required()->searchable()
                ->options(fn () => app(AccessibleMaintenanceSitesQuery::class)->for(auth()->user(), PermissionKey::MaintenanceDispatch)->orderBy('name')->pluck('name', 'id')->all())
                ->live()->afterStateUpdated(fn (Set $set) => $set('asset_id', null))->disabled($editing)->dehydrated(),
            Select::make('asset_type')->label('Asset type')->options(['station' => 'Charging station', 'evse' => 'EVSE', 'connector' => 'Connector', 'component' => 'Component'])->default('station')->required()
                ->live()->afterStateUpdated(fn (Set $set) => $set('asset_id', null))->disabled($editing)->dehydrated(),
            Select::make('asset_id')->label('Charging station')->required()->searchable()
                ->options(fn (Get $get) => ChargingStation::query()->where('site_id', $get('site_id'))
                    ->whereIn('site_id', app(AccessibleMaintenanceSitesQuery::class)->for(auth()->user(), PermissionKey::MaintenanceDispatch)->select('id'))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => $get('asset_type') === 'station')->disabled($editing)->dehydrated(),
            TextInput::make('asset_id')->label('Asset identifier')->required()->ulid()
                ->helperText('Copy the identifier from the EVSE, connector or component record. It must belong to the selected site.')
                ->visible(fn (Get $get): bool => $get('asset_type') !== 'station')->disabled($editing)->dehydrated(),
            Select::make('priority_id')->label('Priority')->required()->options(fn () => MaintenancePriority::query()->where('is_active', true)->pluck('name', 'id')->all()),
        ];
    }

    public static function createWorkOrder(): Action
    {
        return Action::make('createWorkOrder')->label('New work order')->visible(fn (): bool => self::allowed())
            ->schema([
                ...self::assetFields(),
                Select::make('work_type')->options(['corrective' => 'Corrective', 'inspection' => 'Inspection', 'vendor_repair' => 'Vendor repair'])->default('corrective')->required(),
                TextInput::make('title')->required()->maxLength(200),
                Textarea::make('description')->required()->maxLength(10000),
                Toggle::make('safety_critical')->label('Safety critical'),
                Toggle::make('verification_required')->label('Require independent verification')->default(true),
                Toggle::make('asset_restriction_required')->label('Restrict asset during maintenance')->default(false),
            ])->action(function (array $data): void {
                abort_unless(self::allowed(), 403);
                $validated = Validator::make($data, (new StoreMaintenanceWorkOrderRequest)->rules())->validate();
                self::authorizeSite((string) $validated['site_id']);
                WorkflowAction::run(fn () => app(WorkOrderWorkflow::class)->create([
                    'work_type' => (string) $validated['work_type'], 'site_id' => (string) $validated['site_id'],
                    'asset_type' => (string) $validated['asset_type'], 'asset_id' => (string) $validated['asset_id'],
                    'priority_id' => (string) $validated['priority_id'], 'title' => (string) $validated['title'],
                    'description' => (string) $validated['description'], 'safety_critical' => (bool) ($validated['safety_critical'] ?? false),
                    'verification_required' => (bool) ($validated['verification_required'] ?? true),
                    'asset_restriction_required' => (bool) ($validated['asset_restriction_required'] ?? false),
                ]));
            })->successNotificationTitle('Work order created');
    }

    public static function createIncident(): Action
    {
        return Action::make('createIncident')->label('Report incident')->visible(fn (): bool => self::allowed())
            ->schema([...self::assetFields(), TextInput::make('title')->required()->maxLength(200), TextInput::make('fault_code')->required()->maxLength(100), Textarea::make('details')->maxLength(10000)])
            ->action(function (array $data): void {
                abort_unless(self::allowed(), 403);
                $rules = (new StoreMaintenanceWorkOrderRequest)->rules();
                $validated = Validator::make($data, array_intersect_key($rules, array_flip(['site_id', 'asset_type', 'asset_id', 'priority_id', 'title'])) + ['fault_code' => ['required', 'string', 'max:100'], 'details' => ['nullable', 'string', 'max:10000']])->validate();
                self::authorizeSite((string) $validated['site_id']);
                WorkflowAction::run(fn () => DB::transaction(fn () => app(MaintenanceIntakeService::class)->openManualIncident([
                    'site_id' => (string) $validated['site_id'], 'asset_type' => (string) $validated['asset_type'],
                    'asset_id' => (string) $validated['asset_id'], 'priority_id' => (string) $validated['priority_id'],
                    'title' => (string) $validated['title'], 'fault_code' => (string) $validated['fault_code'],
                    'details' => isset($validated['details']) ? (string) $validated['details'] : null,
                ])));
            })->successNotificationTitle('Incident recorded');
    }

    /** @return list<Component> */
    public static function planForm(bool $editing = false): array
    {
        return [
            ...self::assetFields($editing), TextInput::make('name')->required()->maxLength(200),
            Select::make('trigger_type')->options(self::triggers())->default('date')->required()->live()->disabled($editing)->dehydrated(),
            TextInput::make('interval_value')->label('Repeat after')->numeric()->integer()->minValue(1)->maxValue(2147483647)->required()
                ->suffix(fn (Get $get): string => match ($get('trigger_type')) {
                    'session_count' => 'sessions', 'energy_wh' => 'Wh', default => 'seconds'
                })
                ->helperText('For a daily date schedule use 86400 seconds; weekly 604800. Usage triggers use cumulative recorded usage; existing occurrences are preserved.'),
            DateTimePicker::make('next_due_at')->label('Next due (UTC)')->timezone('UTC')->required(fn (Get $get): bool => $get('trigger_type') === 'date')->visible(fn (Get $get): bool => $get('trigger_type') === 'date'),
        ];
    }

    /** @return array<string, string> */
    public static function triggers(): array
    {
        return collect(PreventiveTriggerType::cases())->mapWithKeys(fn (PreventiveTriggerType $type): array => [$type->value => str($type->value)->headline()->toString()])->all();
    }

    public static function createPlan(): Action
    {
        return Action::make('createPlan')->label('New preventive plan')->visible(fn (): bool => self::allowed())->schema(self::planForm())
            ->action(fn (array $data) => WorkflowAction::run(fn () => app(PreventivePlanManager::class)->save(auth()->user(), $data)));
    }

    /** @return list<Action> */
    public static function planActions(): array
    {
        return [
            Action::make('editPlan')->label('Edit')->visible(fn (PreventivePlan $record): bool => self::canManageSite((string) $record->site_id))
                ->fillForm(fn (PreventivePlan $record): array => $record->attributesToArray())->schema(self::planForm(true))
                ->action(fn (PreventivePlan $record, array $data) => WorkflowAction::run(fn () => app(PreventivePlanManager::class)->save(auth()->user(), $data, $record))),
            Action::make('togglePlan')->label(fn (PreventivePlan $record): string => $record->is_active ? 'Pause' : 'Resume')
                ->visible(fn (PreventivePlan $record): bool => self::canManageSite((string) $record->site_id))->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (PreventivePlan $record, array $data) => app(PreventivePlanManager::class)->setActive(auth()->user(), $record, ! $record->is_active, (string) $data['reason'])),
        ];
    }

    public static function canManageSite(string $siteId): bool
    {
        return auth()->user() instanceof User && app(AccessibleMaintenanceSitesQuery::class)->for(auth()->user(), PermissionKey::MaintenanceDispatch)->where('id', $siteId)->exists();
    }

    private static function authorizeSite(string $siteId): void
    {
        abort_unless(self::canManageSite($siteId), 403);
    }
}
