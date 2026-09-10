<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\OperatingHours;

use App\Filament\Operator\Resources\OperatingHours\Pages\ListOperatingHours;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
            Checkbox::make('is_closed'),
            TimePicker::make('opens_at')->seconds(false),
            TimePicker::make('closes_at')->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('site.name')->label('Site')->searchable()->sortable(),
            TextColumn::make('day_of_week')->formatStateUsing(fn (int $state): string => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$state] ?? 'Unknown'),
            TextColumn::make('opens_at')->time('H:i')->placeholder('—'),
            TextColumn::make('closes_at')->time('H:i')->placeholder('—'),
            IconColumn::make('is_closed')->boolean(),
        ])->recordActions([
            RecordViewAction::make([
                'site.name' => 'Site',
                'day_of_week' => 'Day of week (0 = Monday)',
                'opens_at' => 'Opens',
                'closes_at' => 'Closes',
                'is_closed' => 'Closed all day',
            ]),
            AuditedEditAction::make('locations.operating_hour.updated', 'site_operating_hour'),
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
        return false;
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
        return false;
    }
}
