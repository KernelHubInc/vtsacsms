<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\MaintenanceIncidents;

use App\Filament\Operator\Resources\MaintenanceIncidents\Pages\ListMaintenanceIncidents;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Maintenance\Application\AccessibleMaintenanceSitesQuery;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\Models\Incident;
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

final class MaintenanceIncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    /** @return Builder<Incident> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return Incident::query()->whereRaw('1 = 0');
        }

        return Incident::query()->whereIn(
            'site_id',
            app(AccessibleMaintenanceSitesQuery::class)->for($user)->select('id'),
        );
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('incident_number')->label('Incident')->searchable(),
            TextColumn::make('title')->wrap()->searchable(),
            TextColumn::make('fault_code')->badge(),
            TextColumn::make('state')->badge(),
            TextColumn::make('occurrence_count')->label('Occurrences')->numeric(),
            TextColumn::make('escalation_level')->label('Escalation')->numeric(),
            TextColumn::make('last_observed_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(IncidentState::cases())->mapWithKeys(
                        fn (IncidentState $state): array => [
                            $state->value => str($state->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'incident_number' => 'Incident',
                    'title' => 'Title',
                    'details' => 'Details',
                    'site.name' => 'Site',
                    'asset_type' => 'Asset type',
                    'asset_id' => 'Asset identifier',
                    'fault_code' => 'Fault code',
                    'state' => 'State',
                    'occurrence_count' => 'Occurrences',
                    'escalation_level' => 'Escalation',
                    'first_observed_at' => 'First observed at (UTC)',
                    'last_observed_at' => 'Last observed at (UTC)',
                ]),
            ])
            ->defaultSort('last_observed_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMaintenanceIncidents::route('/')];
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
