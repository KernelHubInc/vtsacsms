<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Evses;

use App\Filament\Operator\Resources\Evses\Pages\ListEvses;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\DeleteNetworkRecordAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Assets\Application\AccessibleStationsQuery;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
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

final class EvseResource extends Resource
{
    protected static ?string $model = Evse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'EVSEs';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('charging_station_id')->label('Charging station')->searchable()->required()->visibleOn('create')
                ->default(fn ($livewire) => $livewire->tableFilters['station']['value'] ?? null)
                ->options(fn (): array => app(AccessibleStationsQuery::class)->for(auth()->user(), PermissionKey::AssetManage)->orderBy('name')->get()->mapWithKeys(fn ($station): array => [$station->getKey() => $station->name.' · '.$station->charge_point_identity])->all()),
            TextInput::make('uid')->maxLength(100),
            TextInput::make('evse_number')->integer()->minValue(1)->maxValue(65535)->required()->disabledOn('edit')
                ->helperText('The outlet number on this station. Saved numbering is locked to protect session history.'),
            Select::make('lifecycle_status')
                ->options(fn (string $operation): array => collect(AssetLifecycleStatus::cases())->filter(fn ($status): bool => $operation !== 'create' || in_array($status->value, ['draft', 'active'], true))->mapWithKeys(fn ($status) => [$status->value => str($status->value)->headline()])->all())
                ->default('draft')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('station.name')->label('Station')->searchable()->sortable(),
            TextColumn::make('evse_number')->label('EVSE')->sortable(),
            TextColumn::make('uid')->searchable()->placeholder('—'),
            TextColumn::make('lifecycle_status')->badge(),
            TextColumn::make('connectors_count')->counts('connectors')->label('Connectors'),
        ])
            ->filters([
                SelectFilter::make('station')->label('Charging station')->attribute('charging_station_id')
                    ->options(fn (): array => app(AccessibleStationsQuery::class)->for(auth()->user())->pluck('name', 'id')->all())->searchable(),
                SelectFilter::make('lifecycle_status')
                    ->options(collect(AssetLifecycleStatus::cases())->mapWithKeys(
                        fn (AssetLifecycleStatus $status): array => [
                            $status->value => str($status->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'station.name' => 'Station',
                    'evse_number' => 'EVSE number',
                    'uid' => 'UID',
                    'lifecycle_status' => 'Lifecycle',
                    'connectors_count' => 'Connector count',
                    'created_at' => 'Created at (UTC)',
                ]),
                AuditedEditAction::make('assets.evse.updated', 'evse'),
                DeleteNetworkRecordAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEvses::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with('station');

        return $user instanceof User
            ? $query->whereHas('station', fn (Builder $query): Builder => $query->whereIn(
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

        return $record instanceof Evse
            && $user instanceof User
            && app(AuthorizationService::class)->allows(
                $user,
                PermissionKey::AssetManage,
                new ResourceScope(ScopeType::Site, (string) $record->station->site_id),
            );
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record);
    }
}
