<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Warehouses;

use App\Filament\Operator\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Shared\Actions\AuditedCreateAction;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\InventoryCatalogService;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Tenancy\Application\CurrentTenant;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(40)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                        'tenant_id',
                        app(CurrentTenant::class)->get()->tenantId,
                    ),
                ),
            TextInput::make('name')->required()->maxLength(160),
            Select::make('type')
                ->options([
                    'warehouse' => 'Warehouse',
                    'site_stock' => 'Site stock',
                    'technician_stock' => 'Technician stock',
                    'quarantine' => 'Quarantine',
                ])
                ->required(),
            Select::make('site_id')
                ->label('Site')
                ->options(function (): array {
                    $user = auth()->user();

                    return $user instanceof User
                        ? app(AccessibleSitesQuery::class)->for($user)->orderBy('name')->pluck('name', 'id')->all()
                        : [];
                })
                ->searchable()
                ->nullable(),
            TextInput::make('timezone')
                ->required()
                ->maxLength(64)
                ->rule('timezone:all'),
        ])->columns(2);
    }

    public static function auditedCreateAction(): CreateAction
    {
        return AuditedCreateAction::make('inventory.warehouse.created', 'inventory_warehouse');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('site.name')->label('Site')->placeholder('Not site-bound')->sortable(),
                TextColumn::make('timezone'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'warehouse' => 'Warehouse',
                        'site_stock' => 'Site stock',
                        'technician_stock' => 'Technician stock',
                        'quarantine' => 'Quarantine',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Lifecycle')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive')
                    ->placeholder('All warehouses'),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'code' => 'Code',
                    'name' => 'Name',
                    'type' => 'Type',
                    'site.name' => 'Site',
                    'timezone' => 'Timezone',
                    'is_active' => 'Active',
                    'created_at' => 'Created at (UTC)',
                    'updated_at' => 'Updated at (UTC)',
                ]),
                AuditedEditAction::make('inventory.warehouse.updated', 'inventory_warehouse')
                    ->visible(fn (Warehouse $record): bool => $record->is_active),
                Action::make('deactivate')
                    ->color('danger')
                    ->visible(fn (Warehouse $record): bool => $record->is_active)
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->modalDescription('Historical stock movements remain unchanged. New movements cannot target an inactive warehouse.')
                    ->action(function (Warehouse $record, array $data): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setWarehouseActive($record, false, (string) $data['reason']);
                    }),
                Action::make('reactivate')
                    ->color('success')
                    ->visible(fn (Warehouse $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (Warehouse $record): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setWarehouseActive($record, true, 'administrator_reactivation');
                    }),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => ListWarehouses::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn(
                'inventory_warehouses.id',
                app(AccessibleWarehousesQuery::class)->for($user)->select('inventory_warehouses.id'),
            )
            : $query->whereRaw('1 = 0');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
