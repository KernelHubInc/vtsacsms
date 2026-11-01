<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Http\Requests\Api\StoreAdjustmentRequest;
use App\Http\Requests\Api\StoreCountPlanRequest;
use App\Http\Requests\Api\StoreTransferRequest;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\InventoryAdjustmentWorkflow;
use App\Modules\Inventory\Application\StockCountWorkflow;
use App\Modules\Inventory\Application\StockTransferWorkflow;
use App\Modules\Inventory\Domain\CountStatus;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class InventoryActions
{
    private static function allowed(PermissionKey $permission): bool
    {
        return auth()->user() instanceof User && app(AuthorizationService::class)->holdsAnywhere(auth()->user(), $permission);
    }

    private static function warehouse(string $field, PermissionKey $permission = PermissionKey::InventoryOperate): Select
    {
        return Select::make($field)->label(str($field)->replace('_id', '')->headline()->toString())->required()->searchable()->live()
            ->options(fn () => app(AccessibleWarehousesQuery::class)->for(auth()->user(), $permission)->where('is_active', true)->pluck('name', 'id')->all());
    }

    private static function item(): Select
    {
        return Select::make('item_id')->label('Item')->required()->searchable()->options(fn () => InventoryItem::query()->where('is_active', true)->pluck('name', 'id')->all());
    }

    private static function unit(): Select
    {
        return Select::make('uom_id')->label('Unit')->required()->options(fn () => UnitOfMeasure::query()->where('is_active', true)->pluck('name', 'id')->all());
    }

    private static function bin(string $field, string $warehouseField, bool $transit = false): Select
    {
        return Select::make($field)->label(str($field)->replace('_id', '')->headline()->toString())->required()->searchable()
            ->options(fn (Get $get) => InventoryBin::query()->where('warehouse_id', $get('../../'.$warehouseField))->where('is_active', true)
                ->when($transit, fn ($query) => $query->whereHas('stockLocation', fn ($location) => $location->where('custody_type', 'in_transit')))
                ->pluck('code', 'id')->all());
    }

    private static function assertWarehouse(string $id, PermissionKey $permission): void
    {
        abort_unless(auth()->user() instanceof User && app(AccessibleWarehousesQuery::class)->for(auth()->user(), $permission)->whereKey($id)->exists(), 403);
    }

    public static function createTransfer(): Action
    {
        return Action::make('createTransfer')->label('New stock transfer')->visible(fn (): bool => self::allowed(PermissionKey::InventoryOperate))
            ->schema([self::warehouse('source_warehouse_id'), self::warehouse('destination_warehouse_id'),
                Repeater::make('lines')->minItems(1)->maxItems(100)->schema([
                    self::item(), self::unit(), self::bin('source_bin_id', 'source_warehouse_id'), self::bin('in_transit_bin_id', 'source_warehouse_id', true), self::bin('destination_bin_id', 'destination_warehouse_id'),
                    TextInput::make('requested_quantity_base')->label('Quantity (base units)')->integer()->minValue(1)->required(),
                    TextInput::make('lot_id')->label('Lot identifier (if tracked)')->ulid(), TextInput::make('serial_id')->label('Serial identifier (if tracked)')->ulid(),
                ]),
            ])->action(function (array $data): void {
                $data['transfer_number'] = 'TR-'.Str::ulid();
                $data = Validator::make($data, (new StoreTransferRequest)->rules())->validate();
                self::assertWarehouse($data['source_warehouse_id'], PermissionKey::InventoryOperate);
                self::assertWarehouse($data['destination_warehouse_id'], PermissionKey::InventoryOperate);
                foreach ($data['lines'] as &$line) {
                    $line['requested_quantity_base'] = (int) $line['requested_quantity_base'];
                    abort_unless(InventoryBin::query()->whereKey($line['in_transit_bin_id'])->where('warehouse_id', $data['source_warehouse_id'])->exists(), 422);
                }
                unset($line);
                WorkflowAction::run(fn () => app(StockTransferWorkflow::class)->create($data['transfer_number'], $data['source_warehouse_id'], $data['destination_warehouse_id'], array_values($data['lines'])));
            });
    }

    /** @return list<Action> */
    public static function transfers(): array
    {
        $actions = [];
        foreach (['submit' => TransferStatus::Draft, 'approve' => TransferStatus::Submitted, 'dispatch' => TransferStatus::Approved] as $method => $status) {
            $actions[] = Action::make($method)->label(ucfirst($method))->requiresConfirmation()
                ->visible(fn (StockTransfer $record): bool => $record->status === $status && Gate::allows('update', $record))
                ->action(function (StockTransfer $record) use ($method): void {
                    Gate::authorize('update', $record);
                    WorkflowAction::run(fn () => self::locked($record, fn ($locked) => app(StockTransferWorkflow::class)->{$method}($locked)));
                });
        }
        $actions[] = Action::make('receive')->label('Receive stock')->requiresConfirmation()
            ->visible(fn (StockTransfer $record): bool => in_array($record->status, [TransferStatus::Dispatched, TransferStatus::PartiallyReceived, TransferStatus::Discrepancy], true) && Gate::allows('update', $record))
            ->schema(fn (StockTransfer $record): array => $record->lines()->get()->map(fn ($line) => TextInput::make('quantities.'.$line->getKey())->label('Item '.$line->item_id.' · dispatched '.$line->dispatched_quantity_base)->integer()->minValue(0)->maxValue($line->dispatched_quantity_base)->required())->all())
            ->action(function (StockTransfer $record, array $data): void {
                Gate::authorize('update', $record);
                $counts = array_map(intval(...), $data['quantities'] ?? []);
                WorkflowAction::run(fn () => app(StockTransferWorkflow::class)->receive($record, $counts));
            });

        return $actions;
    }

    public static function createAdjustment(): Action
    {
        return Action::make('createAdjustment')->label('Request adjustment')->visible(fn (): bool => self::allowed(PermissionKey::InventoryAdjust))
            ->schema([self::warehouse('warehouse_id', PermissionKey::InventoryAdjust), TextInput::make('reason_code')->required()->maxLength(80), Textarea::make('reason_notes')->required()->maxLength(5000),
                Repeater::make('lines')->minItems(1)->maxItems(100)->schema([self::item(), self::unit(), self::bin('bin_id', 'warehouse_id'),
                    TextInput::make('quantity_delta_base')->label('Quantity change (base units)')->helperText('Positive adds stock; negative removes stock. Independent approval is required.')->integer()->rules(['not_in:0'])->required(),
                    TextInput::make('lot_id')->label('Lot identifier (if tracked)')->ulid(), TextInput::make('serial_id')->label('Serial identifier (if tracked)')->ulid(),
                ]),
            ])->action(function (array $data): void {
                $data['adjustment_number'] = 'ADJ-'.Str::ulid();
                $data = Validator::make($data, (new StoreAdjustmentRequest)->rules())->validate();
                self::assertWarehouse($data['warehouse_id'], PermissionKey::InventoryAdjust);
                foreach ($data['lines'] as &$line) {
                    $line['quantity_delta_base'] = (int) $line['quantity_delta_base'];
                    abort_unless(InventoryBin::query()->whereKey($line['bin_id'])->where('warehouse_id', $data['warehouse_id'])->exists(), 422);
                }
                unset($line);
                WorkflowAction::run(fn () => app(InventoryAdjustmentWorkflow::class)->request($data['adjustment_number'], $data['warehouse_id'], $data['reason_code'], $data['reason_notes'], array_values($data['lines'])));
            });
    }

    /** @return list<Action> */
    public static function adjustments(): array
    {
        $actions = [];
        foreach (['approve' => 'pending_approval', 'reject' => 'pending_approval', 'post' => 'approved'] as $method => $status) {
            $actions[] = Action::make($method)->label(ucfirst($method))->requiresConfirmation()
                ->visible(fn (AdjustmentRequest $record): bool => $record->status === $status && Gate::allows('update', $record))
                ->schema($method === 'reject' ? [Textarea::make('reason')->required()->maxLength(500)] : [])
                ->action(function (AdjustmentRequest $record, array $data) use ($method): void {
                    Gate::authorize('update', $record);
                    WorkflowAction::run(fn () => self::locked($record, fn ($locked) => $method === 'reject' ? app(InventoryAdjustmentWorkflow::class)->reject($locked, (string) $data['reason']) : app(InventoryAdjustmentWorkflow::class)->{$method}($locked)));
                });
        }

        return $actions;
    }

    public static function createCount(): Action
    {
        return Action::make('createCount')->label('Schedule count')->visible(fn (): bool => self::allowed(PermissionKey::InventoryOperate))
            ->schema([self::warehouse('warehouse_id'), Select::make('count_type')->options(['physical' => 'Physical', 'cycle' => 'Cycle'])->default('cycle')->required(), DatePicker::make('scheduled_for')->required(), Toggle::make('blind_count')->default(true),
                Repeater::make('sheets')->minItems(1)->maxItems(1)->schema([
                    Select::make('stock_location_id')->label('Stock location')->required()->live()->options(fn (Get $get) => StockLocation::query()->where('warehouse_id', $get('../../warehouse_id'))->where('is_active', true)->pluck('name', 'id')->all()),
                    Repeater::make('lines')->minItems(1)->maxItems(100)->schema([self::item(), Select::make('bin_id')->label('Bin')->required()->options(fn (Get $get) => InventoryBin::query()->where('stock_location_id', $get('../../stock_location_id'))->where('is_active', true)->pluck('code', 'id')->all()), TextInput::make('lot_id')->ulid(), TextInput::make('serial_id')->ulid()]),
                ]),
            ])->action(function (array $data): void {
                $data['plan_number'] = 'COUNT-'.Str::ulid();
                $data = Validator::make($data, (new StoreCountPlanRequest)->rules())->validate();
                self::assertWarehouse($data['warehouse_id'], PermissionKey::InventoryOperate);
                foreach ($data['sheets'] as $sheet) {
                    abort_unless(StockLocation::query()->whereKey($sheet['stock_location_id'])->where('warehouse_id', $data['warehouse_id'])->exists(), 422);
                }
                WorkflowAction::run(fn () => app(StockCountWorkflow::class)->create($data['plan_number'], $data['count_type'], $data['warehouse_id'], $data['scheduled_for'], (bool) $data['blind_count'], array_values($data['sheets'])));
            });
    }

    /** @return list<Action> */
    public static function counts(): array
    {
        $actions = [];
        foreach (['start' => CountStatus::Scheduled, 'approve' => CountStatus::Review, 'post' => CountStatus::Approved] as $method => $status) {
            $permission = $method === 'start' ? PermissionKey::InventoryOperate : PermissionKey::InventoryAdjust;
            $actions[] = Action::make($method)->label(ucfirst($method))->requiresConfirmation()
                ->visible(fn (CountPlan $record): bool => $record->status === $status && self::canManageCount($record, $permission))
                ->action(function (CountPlan $record) use ($method, $permission): void {
                    abort_unless(self::canManageCount($record, $permission), 403);
                    WorkflowAction::run(fn () => self::locked($record, fn ($locked) => app(StockCountWorkflow::class)->{$method}($locked)));
                });
        }
        $actions[] = Action::make('recordCount')->label('Record count sheet')
            ->visible(fn (CountPlan $record): bool => $record->status === CountStatus::InProgress && self::canManageCount($record, PermissionKey::InventoryOperate))
            ->schema([
                Select::make('sheet_id')->label('Sheet')->required()->live()->options(fn (CountPlan $record) => $record->sheets()->where('status', 'in_progress')->get()->mapWithKeys(fn ($sheet): array => [$sheet->getKey() => $sheet->stock_location_id.' · round '.$sheet->round])->all()),
                TextInput::make('recount_threshold')->label('Recount tolerance (base units)')->helperText('A larger variance requires a recount. All stock changes still need independent approval.')->default(0)->integer()->minValue(0)->required(),
                Repeater::make('counts')->label('Observed quantities')->addActionLabel('Add counted item')->minItems(1)->schema([
                    Select::make('line_id')->label('Count line')->required()->options(fn (Get $get, CountPlan $record) => $record->sheets()->whereKey($get('../../sheet_id'))->first()?->lines()->get()->mapWithKeys(fn ($line): array => [$line->getKey() => $line->item_id.' · bin '.$line->bin_id])->all() ?? []),
                    TextInput::make('quantity')->integer()->minValue(0)->required(),
                ]),
            ])->action(function (CountPlan $record, array $data): void {
                abort_unless(self::canManageCount($record, PermissionKey::InventoryOperate), 403);
                Validator::make($data, ['sheet_id' => 'required|ulid', 'recount_threshold' => 'required|integer|min:0', 'counts' => 'required|array', 'counts.*.line_id' => 'required|ulid|distinct', 'counts.*.quantity' => 'required|integer|min:0'])->validate();
                $sheet = $record->sheets()->whereKey($data['sheet_id'])->firstOrFail();
                $counts = [];
                foreach ($data['counts'] as $line) {
                    $counts[(string) $line['line_id']] = (int) $line['quantity'];
                }
                WorkflowAction::run(fn () => app(StockCountWorkflow::class)->submitSheet($sheet, $counts, (int) $data['recount_threshold']));
            });
        $actions[] = Action::make('recount')->label('Start required recount')->requiresConfirmation()
            ->visible(fn (CountPlan $record): bool => $record->status === CountStatus::RecountRequired && self::canManageCount($record, PermissionKey::InventoryOperate))
            ->action(function (CountPlan $record): void {
                abort_unless(self::canManageCount($record, PermissionKey::InventoryOperate), 403);
                WorkflowAction::run(function () use ($record): void {
                    $sheet = $record->sheets()->where('status', 'submitted')->orderByDesc('round')->firstOrFail();
                    app(StockCountWorkflow::class)->createRecount($sheet);
                });
            });

        return $actions;
    }

    private static function canManageCount(CountPlan $record, PermissionKey $permission): bool
    {
        return auth()->user() instanceof User && app(AccessibleWarehousesQuery::class)->for(auth()->user(), $permission)->whereKey($record->warehouse_id)->exists();
    }

    private static function locked(Model $record, callable $callback): mixed
    {
        return DB::transaction(function () use ($record, $callback): mixed {
            $locked = $record->newQuery()->lockForUpdate()->whereKey($record->getKey())->firstOrFail();
            $before = $locked->attributesToArray();
            $result = $callback($locked);
            app(AuditRecorder::class)->record(new AuditEntry('inventory.portal.workflow', $record->getTable(), (string) $record->getKey(), AuditResult::Succeeded, before: $before, after: $locked->refresh()->attributesToArray()));

            return $result;
        });
    }
}
