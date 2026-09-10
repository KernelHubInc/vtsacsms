<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\StockTransfers;

use App\Filament\Operator\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'transfer_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transfer_number')->label('Transfer')->searchable()->sortable(),
                TextColumn::make('source_warehouse_id')->label('Source')->toggleable(),
                TextColumn::make('destination_warehouse_id')->label('Destination')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('dispatched_at')->dateTime(),
                TextColumn::make('received_at')->dateTime(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'pending_approval' => 'Pending approval',
                    'approved' => 'Approved',
                    'in_transit' => 'In transit',
                    'partially_received' => 'Partially received',
                    'received' => 'Received',
                    'canceled' => 'Canceled',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'transfer_number' => 'Transfer',
                    'source_warehouse_id' => 'Source warehouse',
                    'destination_warehouse_id' => 'Destination warehouse',
                    'status' => 'Status',
                    'notes' => 'Notes',
                    'dispatched_at' => 'Dispatched at (UTC)',
                    'received_at' => 'Received at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockTransfers::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }
        $warehouseIds = app(AccessibleWarehousesQuery::class)->for($user)->select('id');

        return $query
            ->whereIn('source_warehouse_id', clone $warehouseIds)
            ->whereIn('destination_warehouse_id', clone $warehouseIds);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
