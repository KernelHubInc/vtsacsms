<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Connectors;

use App\Filament\Operator\Resources\Connectors\Pages\ListConnectors;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\DeleteNetworkRecordAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Assets\Application\AccessibleStationsQuery;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class ConnectorResource extends Resource
{
    protected static ?string $model = Connector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('evse_id')->label('Station / EVSE')->searchable()->required()->visibleOn('create')
                ->options(fn (): array => Evse::query()->with('station')->whereIn('charging_station_id', app(AccessibleStationsQuery::class)->for(auth()->user(), PermissionKey::AssetManage)->select('charging_stations.id'))->get()->mapWithKeys(fn (Evse $evse): array => [$evse->getKey() => $evse->station->name.' · '.$evse->station->charge_point_identity.' / EVSE '.$evse->evse_number])->all()),
            TextInput::make('connector_number')->integer()->minValue(1)->maxValue(65535)->required()->disabledOn('edit')
                ->helperText('Match the device-reported OCPP number. OCPP 1.6 numbers must be unique across the station. Numbering is locked after creation.'),
            Select::make('connector_standard_id')->label('Plug standard')->required()->searchable()->disabledOn('edit')
                ->options(fn (): array => ConnectorStandard::query()->whereNull('archived_at')->pluck('name', 'id')->all()),
            Select::make('charging_current_type_id')->label('Current type')->required()->disabledOn('edit')
                ->options(fn (): array => ChargingCurrentType::query()->whereNull('archived_at')->pluck('name', 'id')->all()),
            TextInput::make('maximum_power_w')->label('Maximum power (W)')->helperText('Enter watts: 7 kW = 7000 W.')->integer()->minValue(1)->maxValue(2000000)->required(),
            Select::make('lifecycle_status')
                ->options(fn (string $operation): array => collect(AssetLifecycleStatus::cases())->filter(fn ($status): bool => $operation !== 'create' || in_array($status->value, ['draft', 'active'], true))->mapWithKeys(fn ($status) => [$status->value => str($status->value)->headline()])->all())
                ->default('draft')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('evse.station.name')->label('Station')->searchable()->sortable(),
            TextColumn::make('evse.evse_number')->label('EVSE'),
            TextColumn::make('connector_number')->label('Connector')->sortable(),
            TextColumn::make('standard.name')->label('Standard')->placeholder('—'),
            TextColumn::make('maximum_power_w')->label('Power (W)')->numeric()->sortable(),
            TextColumn::make('lifecycle_status')->badge(),
        ])
            ->filters([
                SelectFilter::make('station')->label('Charging station')
                    ->options(fn (): array => app(AccessibleStationsQuery::class)->for(auth()->user())->pluck('name', 'id')->all())->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $id): Builder => $query->whereHas('evse', fn (Builder $query): Builder => $query->where('charging_station_id', $id)))),
                SelectFilter::make('lifecycle_status')
                    ->options(collect(AssetLifecycleStatus::cases())->mapWithKeys(
                        fn (AssetLifecycleStatus $status): array => [
                            $status->value => str($status->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'evse.station.name' => 'Station',
                    'evse.evse_number' => 'EVSE',
                    'connector_number' => 'Connector',
                    'standard.name' => 'Standard',
                    'maximum_power_w' => 'Maximum power (W)',
                    'lifecycle_status' => 'Lifecycle',
                    'serial_number' => 'Serial number',
                    'qr_identifier' => 'QR identifier',
                ]),
                AuditedEditAction::make('assets.connector.updated', 'connector'),
                DeleteNetworkRecordAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListConnectors::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['evse.station', 'standard']);

        return $user instanceof User
            ? $query->whereHas('evse.station', fn (Builder $query): Builder => $query->whereIn(
                'site_id',
                app(AccessibleSitesQuery::class)->for($user)->select('sites.id'),
            ))
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::AssetView);
    }

    public static function canCreate(): bool
    {
        return parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record instanceof Connector
            && $user instanceof User
            && app(AuthorizationService::class)->allows(
                $user,
                PermissionKey::AssetManage,
                new ResourceScope(ScopeType::Site, (string) $record->evse->station->site_id),
            );
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record);
    }
}
