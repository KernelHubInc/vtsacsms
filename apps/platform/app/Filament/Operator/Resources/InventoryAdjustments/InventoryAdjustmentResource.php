<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\InventoryAdjustments;

use App\Filament\Operator\Resources\InventoryAdjustments\Pages\ListInventoryAdjustments;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class InventoryAdjustmentResource extends Resource
{
    protected static ?string $model = AdjustmentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'adjustment_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('adjustment_number')->label('Adjustment')->searchable()->sortable(),
                TextColumn::make('warehouse_id')->label('Warehouse')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('reason_code')->badge(),
                TextColumn::make('requested_by')->toggleable(),
                TextColumn::make('approved_at')->dateTime(),
                TextColumn::make('posted_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'pending_approval' => 'Pending approval',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'posted' => 'Posted',
                    'canceled' => 'Canceled',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'adjustment_number' => 'Adjustment',
                    'warehouse_id' => 'Warehouse',
                    'status' => 'Status',
                    'reason_code' => 'Reason code',
                    'notes' => 'Notes',
                    'requested_by' => 'Requested by',
                    'approved_at' => 'Approved at (UTC)',
                    'posted_at' => 'Posted at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListInventoryAdjustments::route('/')];
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
