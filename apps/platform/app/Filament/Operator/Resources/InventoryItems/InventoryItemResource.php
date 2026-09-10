<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\InventoryItems;

use App\Filament\Operator\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Shared\Actions\AuditedCreateAction;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Modules\Inventory\Application\InventoryCatalogService;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use App\Modules\Inventory\Domain\TrackingType;
use App\Modules\Inventory\Domain\ValuationMethod;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')
                ->required()
                ->maxLength(80)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                        'tenant_id',
                        app(CurrentTenant::class)->get()->tenantId,
                    ),
                ),
            TextInput::make('name')->required()->maxLength(200),
            Textarea::make('description')->columnSpanFull(),
            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => ItemCategory::query()
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            Select::make('base_uom_id')
                ->label('Base unit')
                ->options(fn (): array => UnitOfMeasure::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            Select::make('tracking_type')
                ->options(collect(TrackingType::cases())->mapWithKeys(
                    fn (TrackingType $type): array => [$type->value => str($type->value)->headline()->toString()],
                )->all())
                ->required(),
            Select::make('valuation_method')
                ->options(collect(ValuationMethod::cases())->mapWithKeys(
                    fn (ValuationMethod $method): array => [$method->value => str($method->value)->headline()->toString()],
                )->all())
                ->required(),
            TextInput::make('currency')
                ->required()
                ->length(3)
                ->regex('/^[A-Za-z]{3}$/')
                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper($state)),
            TextInput::make('standard_cost_minor')
                ->label('Standard cost (minor units)')
                ->integer()
                ->minValue(0),
        ])->columns(2);
    }

    public static function auditedCreateAction(): CreateAction
    {
        return AuditedCreateAction::make('inventory.item.created', 'inventory_item');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('category.name')->sortable(),
                TextColumn::make('baseUnit.code')->label('UOM'),
                TextColumn::make('tracking_type')->badge(),
                TextColumn::make('valuation_method')->badge(),
                TextColumn::make('standard_cost_minor')
                    ->label('Standard cost (minor)')
                    ->numeric()
                    ->description(fn (InventoryItem $record): string => $record->currency),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('tracking_type')
                    ->options(collect(TrackingType::cases())->mapWithKeys(
                        fn (TrackingType $type): array => [$type->value => str($type->value)->headline()->toString()],
                    )->all()),
                TernaryFilter::make('is_active')
                    ->label('Lifecycle')
                    ->trueLabel('Active')
                    ->falseLabel('Archived')
                    ->placeholder('All items'),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'sku' => 'SKU',
                    'name' => 'Name',
                    'description' => 'Description',
                    'category.name' => 'Category',
                    'baseUnit.code' => 'Base unit',
                    'tracking_type' => 'Tracking',
                    'valuation_method' => 'Valuation',
                    'standard_cost_minor' => 'Standard cost (minor units)',
                    'currency' => 'Currency',
                    'is_active' => 'Active',
                    'archived_at' => 'Archived at (UTC)',
                ]),
                AuditedEditAction::make('inventory.item.updated', 'inventory_item')
                    ->visible(fn (InventoryItem $record): bool => $record->archived_at === null),
                Action::make('archive')
                    ->color('danger')
                    ->visible(fn (InventoryItem $record): bool => $record->archived_at === null)
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->modalDescription('The item remains in stock history and existing documents, but is unavailable for new transactions.')
                    ->action(function (InventoryItem $record, array $data): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setItemArchived($record, true, (string) $data['reason']);
                    }),
                Action::make('restore')
                    ->color('success')
                    ->visible(fn (InventoryItem $record): bool => $record->archived_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription('The item will become available for new inventory transactions.')
                    ->action(function (InventoryItem $record): void {
                        Gate::authorize('update', $record);
                        app(InventoryCatalogService::class)->setItemArchived($record, false, 'administrator_restore');
                    }),
            ])
            ->defaultSort('sku');
    }

    public static function getPages(): array
    {
        return ['index' => ListInventoryItems::route('/')];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
