<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingStations;

use App\Filament\Operator\Resources\ChargingStations\Pages\CreateChargingStation;
use App\Filament\Operator\Resources\ChargingStations\Pages\EditChargingStation;
use App\Filament\Operator\Resources\ChargingStations\Pages\ListChargingStations;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Assets\Application\AccessibleStationsQuery;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Charging\Application\StationConnectionQuery;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class ChargingStationResource extends Resource
{
    protected static ?string $model = ChargingStation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')
                ->label('Site')
                ->options(function (): array {
                    $user = auth()->user();

                    return $user instanceof User
                        ? app(AccessibleSitesQuery::class)->for($user)->orderBy('name')->pluck('name', 'id')->all()
                        : [];
                })
                ->searchable()
                ->required()
                ->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('charge_point_identity')
                ->required()
                ->maxLength(120)
                ->regex('/\A[A-Za-z0-9._-]+\z/')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Select::make('ocpp_version_id')->label('OCPP version')
                ->options(fn (): array => OcppVersion::query()->whereIn('code', ['1.6J', '2.0.1'])->pluck('code', 'id')->all())
                ->default(fn () => OcppVersion::query()->where('code', '1.6J')->value('id'))
                ->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('serial_number')
                ->required()
                ->maxLength(160)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                        'tenant_id',
                        app(CurrentTenant::class)->get()->tenantId,
                    ),
                )
                ->disabledOn('edit'),
            TextInput::make('qr_identifier')
                ->required()
                ->maxLength(120)
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Select::make('lifecycle_status')->options(collect(AssetLifecycleStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str($status->value)->headline()]))->required(),
            Select::make('is_public')->boolean(trueLabel: 'Public', falseLabel: 'Private')->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $table->modifyQueryUsing(fn (Builder $query): Builder => $query
            ->leftJoin('charging_station_connections as connection', function ($join): void {
                $join->on('connection.charging_station_id', '=', 'charging_stations.id')
                    ->on('connection.tenant_id', '=', 'charging_stations.tenant_id');
            })
            ->select('charging_stations.*', 'connection.connected as ocpp_connected', 'connection.last_seen_at as ocpp_last_seen_at'));

        return $table->poll('5s')->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('site.name')->label('Site')->sortable(),
            TextColumn::make('charge_point_identity')->searchable(),
            TextColumn::make('connection_status')->label('Connection')->badge()
                ->state(fn (ChargingStation $record): string => app(StationConnectionQuery::class)->status(
                    $record->getAttribute('ocpp_connected') === null ? null : (bool) $record->getAttribute('ocpp_connected'),
                    $record->getAttribute('ocpp_last_seen_at'),
                ))
                ->color(fn (string $state): string => match ($state) {
                    'online' => 'success', 'offline' => 'danger', default => 'gray',
                }),
            TextColumn::make('ocpp_last_seen_at')->label('Last device message (UTC)')->dateTime()->placeholder('Not seen'),
            TextColumn::make('lifecycle_status')->badge(),
            IconColumn::make('is_public')->boolean(),
        ])
            ->filters([
                SelectFilter::make('lifecycle_status')
                    ->options(collect(AssetLifecycleStatus::cases())->mapWithKeys(
                        fn (AssetLifecycleStatus $status): array => [
                            $status->value => str($status->value)->headline()->toString(),
                        ],
                    )->all()),
                SelectFilter::make('is_public')->options([1 => 'Public', 0 => 'Private']),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'name' => 'Name',
                    'site.name' => 'Site',
                    'charge_point_identity' => 'Charge point identity',
                    'serial_number' => 'Serial number',
                    'qr_identifier' => 'QR identifier',
                    'lifecycle_status' => 'Lifecycle',
                    'is_public' => 'Public',
                    'commissioned_at' => 'Commissioned at (UTC)',
                    'retired_at' => 'Retired at (UTC)',
                ]),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChargingStations::route('/'),
            'create' => CreateChargingStation::route('/create'),
            'edit' => EditChargingStation::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('charging_stations.id', app(AccessibleStationsQuery::class)->for($user)->select('charging_stations.id'))
            : $query->whereRaw('1 = 0');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
