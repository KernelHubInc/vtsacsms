<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ReorderPoints;

use App\Filament\Operator\Resources\ReorderPoints\Pages\ListReorderPoints;
use App\Filament\Shared\Actions\AuditedCreateAction;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\InventoryCatalogService;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ReorderPoint;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class ReorderPointResource extends Resource
{
    protected static ?string $model = ReorderPoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('item_id')
                ->label('Item')
                ->options(fn (): array => InventoryItem::query()
                    ->where('is_active', true)
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            Select::make('warehouse_id')
                ->label('Warehouse')
                ->options(function (): array {
                    $user = auth()->user();

                    return $user instanceof User
                        ? app(AccessibleWarehousesQuery::class)
                            ->for($user, PermissionKey::InventoryOperate)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()
                        : [];
                })
                ->searchable()
                ->required(),
            TextInput::make('minimum_quantity_base')->label('Minimum quantity')->integer()->minValue(0)->required(),
            TextInput::make('reorder_quantity_base')->label('Reorder quantity')->integer()->minValue(1)->required(),
            TextInput::make('target_quantity_base')->label('Target quantity')->integer()->minValue(0)->gte('minimum_quantity_base')->required(),
        ])->columns(2);
    }

    public static function auditedCreateAction(): CreateAction
    {
        return AuditedCreateAction::make('inventory.reorder_point.created', 'inventory_reorder_point');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.name')->label('Item')->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable(),
                TextColumn::make('minimum_quantity_base')->label('Minimum')->numeric(),
                TextColumn::make('reorder_quantity_base')->label('Reorder quantity')->numeric(),
                TextColumn::make('target_quantity_base')->label('Target')->numeric(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'item.name' => 'Item',
                    'warehouse.name' => 'Warehouse',
                    'minimum_quantity_base' => 'Minimum quantity',
                    'reorder_quantity_base' => 'Reorder quantity',
                    'target_quantity_base' => 'Target quantity',
                    'is_active' => 'Active',
                    'updated_at' => 'Updated at (UTC)',
                ]),
                AuditedEditAction::make('inventory.reorder_point.updated', 'inventory_reorder_point')
                    ->visible(fn (ReorderPoint $record): bool => $record->is_active),
                Action::make('deactivate')
                    ->color('danger')
                    ->visible(fn (ReorderPoint $record): bool => $record->is_active)
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(function (ReorderPoint $record, array $data): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setReorderPointActive($record, false, (string) $data['reason']);
                    }),
                Action::make('reactivate')
                    ->color('success')
                    ->visible(fn (ReorderPoint $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (ReorderPoint $record): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setReorderPointActive($record, true, 'administrator_reactivation');
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListReorderPoints::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('warehouse_id', app(AccessibleWarehousesQuery::class)->for($user)->select('id'))
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::InventoryView);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
