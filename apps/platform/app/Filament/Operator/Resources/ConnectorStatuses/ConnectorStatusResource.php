<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ConnectorStatuses;

use App\Filament\Operator\Resources\ConnectorStatuses\Pages\ListConnectorStatuses;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Locations\Application\AccessibleSitesQuery;
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

final class ConnectorStatusResource extends Resource
{
    protected static ?string $model = ConnectorStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Status and faults';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('connector_id')->label('Connector')->copyable()->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('observed_at')->dateTime()->sortable(),
            TextColumn::make('stale_after_seconds')->label('Stale after (s)')->numeric(),
        ])->filters([
            SelectFilter::make('status')->options([
                'available' => 'Available', 'occupied' => 'Occupied', 'faulted' => 'Faulted',
                'offline' => 'Offline', 'unknown' => 'Unknown',
            ]),
        ])->recordActions([
            RecordViewAction::make([
                'connector_id' => 'Connector',
                'status' => 'Status',
                'observed_at' => 'Observed at (UTC)',
                'stale_after_seconds' => 'Stale after (seconds)',
                'vendor_error_code' => 'Vendor error code',
                'info' => 'Safe status information',
            ]),
        ])->defaultSort('observed_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListConnectorStatuses::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('connector_id', fn ($query) => $query
                ->from('connectors as connector')
                ->join('evses as evse', 'evse.id', '=', 'connector.evse_id')
                ->join('charging_stations as station', 'station.id', '=', 'evse.charging_station_id')
                ->whereIn('station.site_id', app(AccessibleSitesQuery::class)->for($user)->select('sites.id'))
                ->select('connector.id'))
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::AssetView);
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
