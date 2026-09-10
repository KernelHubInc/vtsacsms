<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PreventivePlans;

use App\Filament\Operator\Resources\PreventivePlans\Pages\ListPreventivePlans;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Maintenance\Application\AccessibleMaintenanceSitesQuery;
use App\Modules\Maintenance\Domain\Models\PreventivePlan;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PreventivePlanResource extends Resource
{
    protected static ?string $model = PreventivePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    /** @return Builder<PreventivePlan> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return PreventivePlan::query()->whereRaw('1 = 0');
        }

        return PreventivePlan::query()->whereIn(
            'site_id',
            app(AccessibleMaintenanceSitesQuery::class)->for($user)->select('id'),
        );
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('plan_number')->label('Plan')->searchable(),
            TextColumn::make('name')->wrap()->searchable(),
            TextColumn::make('asset_type')->badge(),
            TextColumn::make('trigger_type')->badge(),
            TextColumn::make('interval_value')->numeric(),
            TextColumn::make('next_due_at')->dateTime()->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])
            ->filters([
                SelectFilter::make('trigger_type')->options([
                    'date' => 'Date',
                    'runtime' => 'Runtime',
                    'session_count' => 'Session count',
                    'energy_delivered' => 'Energy delivered',
                ]),
                TernaryFilter::make('is_active')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive')
                    ->placeholder('All plans'),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'plan_number' => 'Plan',
                    'name' => 'Name',
                    'site.name' => 'Site',
                    'asset_type' => 'Asset type',
                    'asset_id' => 'Asset identifier',
                    'trigger_type' => 'Trigger type',
                    'interval_value' => 'Interval',
                    'next_due_at' => 'Next due at (UTC)',
                    'is_active' => 'Active',
                ]),
            ])
            ->defaultSort('next_due_at');
    }

    public static function getPages(): array
    {
        return ['index' => ListPreventivePlans::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::MaintenanceView);
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
