<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Sites;

use App\Filament\Operator\Resources\Sites\Pages\CreateSite;
use App\Filament\Operator\Resources\Sites\Pages\EditSite;
use App\Filament\Operator\Resources\Sites\Pages\ListSites;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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

final class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('code')
                ->required()
                ->maxLength(80)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                        'tenant_id',
                        app(CurrentTenant::class)->get()->tenantId,
                    ),
                ),
            Select::make('operator_organization_id')
                ->label('Operator')
                ->options(fn (): array => Organization::query()
                    ->where('type', OrganizationType::ChargePointOperator)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            Select::make('site_host_organization_id')
                ->label('Site host')
                ->options(fn (): array => Organization::query()
                    ->where('type', OrganizationType::SiteHost)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->nullable(),
            Select::make('lifecycle_status')->options(collect(SiteLifecycleStatus::cases())->mapWithKeys(fn ($status) => [$status->value => str($status->value)->headline()]))->required(),
            TextInput::make('timezone')->required()->maxLength(64),
            Select::make('site_type')
                ->options([
                    'public_parking' => 'Public parking',
                    'retail' => 'Retail',
                    'workplace' => 'Workplace',
                    'fleet_depot' => 'Fleet depot',
                    'residential' => 'Residential',
                    'highway' => 'Highway',
                ])
                ->required(),
            TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
            View::make('filament.shared.forms.location-picker')
                ->viewData(fn (Get $get): array => [
                    'latitude' => $get('latitude'),
                    'longitude' => $get('longitude'),
                    'mapConfig' => app(MapConfigurationResolver::class)
                        ->forSurface(Filament::getCurrentPanel()?->getId() === 'operator'
                            ? MapSurface::Operator
                            : MapSurface::Admin)
                        ->toWebArray(),
                ])
                ->columnSpanFull(),
            TextInput::make('address_line_1')->maxLength(180),
            Textarea::make('description')->columnSpanFull(),
            Select::make('is_public')->options([0 => 'Private', 1 => 'Public'])->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->searchable(),
            TextColumn::make('lifecycle_status')->badge(),
            IconColumn::make('is_public')->boolean(),
            TextColumn::make('timezone'),
        ])
            ->filters([
                SelectFilter::make('lifecycle_status')
                    ->options(collect(SiteLifecycleStatus::cases())->mapWithKeys(
                        fn (SiteLifecycleStatus $status): array => [
                            $status->value => str($status->value)->headline()->toString(),
                        ],
                    )->all()),
                SelectFilter::make('is_public')->options([1 => 'Public', 0 => 'Private']),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'name' => 'Name',
                    'code' => 'Code',
                    'operatorOrganization.name' => 'Operator',
                    'siteHostOrganization.name' => 'Site host',
                    'lifecycle_status' => 'Lifecycle',
                    'site_type' => 'Site type',
                    'is_public' => 'Public',
                    'timezone' => 'Timezone',
                    'address_line_1' => 'Address',
                    'latitude' => 'Latitude',
                    'longitude' => 'Longitude',
                ]),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('sites.id', app(AccessibleSitesQuery::class)->for($user)->select('sites.id'))
            : $query->whereRaw('1 = 0');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
