<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CountPlans;

use App\Filament\Operator\Resources\CountPlans\Pages\ListCountPlans;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class CountPlanResource extends Resource
{
    protected static ?string $model = CountPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'plan_number';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan_number')->label('Count plan')->searchable()->sortable(),
                TextColumn::make('count_type')->badge(),
                TextColumn::make('warehouse_id')->label('Warehouse')->toggleable(),
                TextColumn::make('status')->badge(),
                IconColumn::make('blind_count')->boolean(),
                TextColumn::make('scheduled_for')->date()->sortable(),
                TextColumn::make('approved_at')->dateTime(),
                TextColumn::make('posted_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'in_progress' => 'In progress',
                    'review' => 'Review',
                    'approved' => 'Approved',
                    'posted' => 'Posted',
                    'canceled' => 'Canceled',
                ]),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'plan_number' => 'Count plan',
                    'count_type' => 'Count type',
                    'warehouse_id' => 'Warehouse',
                    'status' => 'Status',
                    'blind_count' => 'Blind count',
                    'scheduled_for' => 'Scheduled for',
                    'approved_at' => 'Approved at (UTC)',
                    'posted_at' => 'Posted at (UTC)',
                ]),
            ])
            ->defaultSort('scheduled_for', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCountPlans::route('/')];
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
