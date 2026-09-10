<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\StockMovements;

use App\Filament\Operator\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('movement_type')->badge()->sortable(),
                TextColumn::make('item_id')->label('Item')->searchable(),
                TextColumn::make('quantity_base')->label('Quantity')->numeric(),
                TextColumn::make('from_bin_id')->label('From bin')->toggleable(),
                TextColumn::make('to_bin_id')->label('To bin')->toggleable(),
                TextColumn::make('reference_type')->badge(),
                TextColumn::make('reference_id')->label('Reference')->toggleable(),
                TextColumn::make('reason_code')->toggleable(),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->options(collect(MovementType::cases())->mapWithKeys(
                        fn (MovementType $type): array => [
                            $type->value => str($type->value)->headline()->toString(),
                        ],
                    )->all()),
                SelectFilter::make('reference_type')
                    ->options(fn (): array => StockMovement::query()
                        ->select('reference_type')
                        ->whereNotNull('reference_type')
                        ->distinct()
                        ->orderBy('reference_type')
                        ->pluck('reference_type', 'reference_type')
                        ->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'movement_type' => 'Movement type',
                    'item_id' => 'Item',
                    'quantity_base' => 'Quantity (base unit)',
                    'from_bin_id' => 'From bin',
                    'to_bin_id' => 'To bin',
                    'unit_cost_minor' => 'Unit cost (minor units)',
                    'total_cost_minor' => 'Total cost (minor units)',
                    'currency' => 'Currency',
                    'reference_type' => 'Reference type',
                    'reference_id' => 'Reference',
                    'reason_code' => 'Reason',
                    'occurred_at' => 'Occurred at (UTC)',
                ]),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }
        $warehouseIds = app(AccessibleWarehousesQuery::class)->for($user)->select('id');
        $binIds = InventoryBin::query()->whereIn('warehouse_id', $warehouseIds)->select('id');

        return $query->where(function (Builder $query) use ($binIds): void {
            $query->whereIn('from_bin_id', clone $binIds)->orWhereIn('to_bin_id', clone $binIds);
        });
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::InventoryView);
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
