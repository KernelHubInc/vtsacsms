<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\GoodsReceiptWorkflow;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\StockLot;
use App\Modules\Inventory\Domain\Models\StockSerial;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class GoodsReceiptActions
{
    private static function allowed(): bool
    {
        return auth()->user() instanceof User
            && app(AuthorizationService::class)->holdsAnywhere(auth()->user(), PermissionKey::InventoryOperate)
            && app(AuthorizationService::class)->allows(auth()->user(), PermissionKey::ProcurementView);
    }

    public static function create(): Action
    {
        return Action::make('receiveGoods')->label('Receive purchase order')->visible(fn (): bool => self::allowed())
            ->schema([
                Select::make('purchase_order_id')->label('Purchase order')->required()->live()->searchable()->options(fn () => PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received'])->pluck('po_number', 'id')->all()),
                Select::make('warehouse_id')->label('Receiving warehouse')->required()->live()->options(fn () => app(AccessibleWarehousesQuery::class)->for(auth()->user(), PermissionKey::InventoryOperate)->where('is_active', true)->pluck('name', 'id')->all()),
                TextInput::make('supplier_delivery_reference')->maxLength(100),
                Repeater::make('lines')->minItems(1)->maxItems(100)->schema([
                    Select::make('purchase_order_line_id')->label('Order line')->required()->options(fn (Get $get) => PurchaseOrder::query()->whereKey($get('../../purchase_order_id'))->first()?->lines()->pluck('description', 'id')->all() ?? []),
                    Select::make('destination_bin_id')->label('Accepted stock bin')->required()->options(fn (Get $get) => InventoryBin::query()->where('warehouse_id', $get('../../warehouse_id'))->where('is_active', true)->pluck('code', 'id')->all()),
                    Select::make('rejection_bin_id')->label('Rejected stock bin')->options(fn (Get $get) => InventoryBin::query()->where('warehouse_id', $get('../../warehouse_id'))->whereHas('stockLocation', fn ($query) => $query->whereIn('custody_type', ['quarantine', 'damaged', 'returns']))->pluck('code', 'id')->all()),
                    TextInput::make('received_quantity_base')->label('Received quantity (base units)')->integer()->minValue(1)->required(), TextInput::make('lot_id')->ulid(), TextInput::make('serial_id')->ulid(),
                ]),
            ])->action(function (array $data): void {
                abort_unless(self::allowed(), 403);
                Validator::make($data, ['purchase_order_id' => 'required|ulid', 'warehouse_id' => 'required|ulid', 'supplier_delivery_reference' => 'nullable|string|max:100', 'lines' => 'required|array|min:1|max:100', 'lines.*.purchase_order_line_id' => 'required|ulid|distinct', 'lines.*.received_quantity_base' => 'required|integer|min:1', 'lines.*.destination_bin_id' => 'required|ulid', 'lines.*.rejection_bin_id' => 'nullable|ulid', 'lines.*.lot_id' => 'nullable|ulid', 'lines.*.serial_id' => 'nullable|ulid'])->validate();
                abort_unless(app(AccessibleWarehousesQuery::class)->for(auth()->user(), PermissionKey::InventoryOperate)->whereKey($data['warehouse_id'])->exists(), 403);
                $order = PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received'])->whereKey($data['purchase_order_id'])->firstOrFail();
                $lines = [];
                foreach ($data['lines'] as $line) {
                    $orderLine = $order->lines()->whereKey($line['purchase_order_line_id'])->firstOrFail();
                    if (! empty($line['lot_id'])) {
                        StockLot::query()->whereKey($line['lot_id'])->where('item_id', $orderLine->item_id)->firstOrFail();
                    }
                    if (! empty($line['serial_id'])) {
                        StockSerial::query()->whereKey($line['serial_id'])->where('item_id', $orderLine->item_id)->firstOrFail();
                    }
                    $lines[] = ['purchase_order_line_id' => (string) $orderLine->getKey(), 'item_id' => (string) $orderLine->item_id, 'uom_id' => (string) $orderLine->uom_id, 'destination_bin_id' => $line['destination_bin_id'], 'rejection_bin_id' => $line['rejection_bin_id'] ?? null, 'lot_id' => $line['lot_id'] ?? null, 'serial_id' => $line['serial_id'] ?? null, 'received_quantity_base' => (int) $line['received_quantity_base']];
                }
                WorkflowAction::run(fn () => app(GoodsReceiptWorkflow::class)->receive(['purchase_order_id' => (string) $order->getKey(), 'supplier_id' => $order->supplier_id, 'warehouse_id' => $data['warehouse_id'], 'receipt_number' => 'GR-'.Str::ulid(), 'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null, 'lines' => $lines]));
            });
    }

    /** @return list<Action> */
    public static function records(): array
    {
        return [
            Action::make('inspect')->label('Record inspection')->visible(fn (GoodsReceipt $record): bool => $record->status === 'pending_inspection' && Gate::allows('update', $record))
                ->schema(fn (GoodsReceipt $record): array => $record->lines()->get()->flatMap(fn ($line): array => [
                    TextInput::make('accepted.'.$line->getKey())->label('Accepted units · item '.$line->item_id.' · received '.$line->received_quantity_base)->integer()->minValue(0)->maxValue($line->received_quantity_base)->required(),
                    TextInput::make('notes.'.$line->getKey())->label('Inspection notes')->maxLength(500),
                ])->all())->requiresConfirmation()
                ->action(function (GoodsReceipt $record, array $data): void {
                    Gate::authorize('update', $record);
                    Validator::make($data, ['accepted' => 'required|array', 'accepted.*' => 'required|integer|min:0', 'notes' => 'nullable|array', 'notes.*' => 'nullable|string|max:500'])->validate();
                    $decisions = $record->lines()->get()->map(function ($line) use ($data): array {
                        abort_unless(array_key_exists($line->getKey(), $data['accepted']), 422);
                        $accepted = (int) $data['accepted'][$line->getKey()];

                        return ['line_id' => (string) $line->getKey(), 'accepted_quantity_base' => $accepted, 'rejected_quantity_base' => (int) $line->received_quantity_base - $accepted, 'inspection_notes' => isset($data['notes'][$line->getKey()]) ? (string) $data['notes'][$line->getKey()] : null];
                    })->values()->all();
                    WorkflowAction::run(fn () => app(GoodsReceiptWorkflow::class)->inspect($record, array_values($decisions)));
                }),
            Action::make('postReceipt')->label('Post stock')->requiresConfirmation()->visible(fn (GoodsReceipt $record): bool => $record->status === 'inspected' && Gate::allows('update', $record))
                ->action(function (GoodsReceipt $record): void {
                    Gate::authorize('update', $record);
                    WorkflowAction::run(fn () => app(GoodsReceiptWorkflow::class)->post($record));
                }),
        ];
    }
}
