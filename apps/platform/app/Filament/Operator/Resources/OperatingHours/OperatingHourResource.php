<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\OperatingHours;

use App\Filament\Operator\Resources\OperatingHours\Pages\ListOperatingHours;
use App\Filament\Shared\Actions\ActionValidation;
use App\Filament\Shared\Actions\DeleteNetworkRecordAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Locations\Application\ConfigureSiteAccess;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class OperatingHourResource extends Resource
{
    protected static ?string $model = SiteOperatingHour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Operating hours';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->label('Site')->required()->searchable()->visibleOn('create')
                ->default(fn ($livewire) => $livewire->tableFilters['site']['value'] ?? null)
                ->options(fn (): array => app(AccessibleSitesQuery::class)->for(auth()->user())->get()->filter(fn ($site): bool => Gate::allows('update', $site))->pluck('name', 'id')->all()),
            Select::make('day_of_week')->label('Day')->options(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])->required()->disabledOn('edit'),
            Checkbox::make('is_closed')->label('Closed all day')->default(false),
            Checkbox::make('is_open_all_day')->label('Open 24 hours')->live()
                ->default(false)
                ->helperText('Closed all day takes priority. Otherwise this replaces the opening and closing times.'),
            TimePicker::make('opens_at')->seconds(false)->format('H:i')->disabled(fn (Get $get): bool => (bool) $get('is_open_all_day'))->helperText('Local site time. Leave times empty only for a closed day.'),
            TimePicker::make('closes_at')->seconds(false)->format('H:i')->disabled(fn (Get $get): bool => (bool) $get('is_open_all_day'))->helperText('Earlier than opening means closing the following day. Equal times are not valid.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('site.name')->label('Site')->searchable()->sortable(),
            TextColumn::make('day_of_week')->formatStateUsing(fn (int $state): string => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$state] ?? 'Unknown'),
            TextColumn::make('opens_at')->time('H:i')->placeholder('—'),
            TextColumn::make('closes_at')->formatStateUsing(fn (?string $state): string => $state === null ? '—' : (str_starts_with($state, '24:00') ? '24:00 (end of day)' : substr($state, 0, 5)))->placeholder('—'),
            IconColumn::make('is_closed')->boolean(),
        ])->filters([
            SelectFilter::make('site')->attribute('site_id')->label('Site')->searchable()
                ->options(fn (): array => app(AccessibleSitesQuery::class)->for(auth()->user())->pluck('name', 'id')->all()),
        ])->recordActions([
            RecordViewAction::make([
                'site.name' => 'Site',
                'day_of_week' => 'Day of week (0 = Monday)',
                'opens_at' => 'Opens',
                'closes_at' => 'Closes',
                'is_closed' => 'Closed all day',
            ]),
            EditAction::make()
                ->mutateRecordDataUsing(function (array $data): array {
                    $data['is_open_all_day'] = str_starts_with($data['closes_at'] ?? '', '24:00');

                    return $data;
                })
                ->using(fn (SiteOperatingHour $record, array $data, $livewire) => ActionValidation::run($livewire, fn () => app(ConfigureSiteAccess::class)->hours(auth()->user(), $data, $record))),
            DeleteNetworkRecordAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOperatingHours::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with('site');

        return $user instanceof User
            ? $query->whereIn('site_id', app(AccessibleSitesQuery::class)->for($user)->select('sites.id'))
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::LocationView);
    }

    public static function canCreate(): bool
    {
        return parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record instanceof SiteOperatingHour
            && $user instanceof User
            && app(AuthorizationService::class)->allows(
                $user,
                PermissionKey::LocationManage,
                new ResourceScope(ScopeType::Site, (string) $record->site_id),
            );
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record);
    }
}
