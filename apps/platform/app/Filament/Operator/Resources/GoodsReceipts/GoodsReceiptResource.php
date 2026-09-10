<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\GoodsReceipts;

use App\Filament\Operator\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'receipt_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('receipt_number')->label('Receipt')->searchable()->sortable(),
                TextColumn::make('purchase_order_id')->label('Purchase order')->toggleable(),
                TextColumn::make('warehouse_id')->label('Warehouse')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('supplier_delivery_reference')->label('Delivery reference'),
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('posted_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'inspection_pending' => 'Inspection pending',
                    'accepted' => 'Accepted',
                    'partially_accepted' => 'Partially accepted',
                    'rejected' => 'Rejected',
                    'posted' => 'Posted',
                    'returned' => 'Returned',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'receipt_number' => 'Receipt',
                    'purchase_order_id' => 'Purchase order',
                    'warehouse_id' => 'Warehouse',
                    'status' => 'Status',
                    'supplier_delivery_reference' => 'Delivery reference',
                    'received_at' => 'Received at (UTC)',
                    'posted_at' => 'Posted at (UTC)',
                ]),
            ])
            ->defaultSort('received_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListGoodsReceipts::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('warehouse_id', app(AccessibleWarehousesQuery::class)->for($user)->select('id'))
            : $query->whereRaw('1 = 0');
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
