<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargerCommands;

use App\Filament\Operator\Resources\ChargerCommands\Pages\ListChargerCommands;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
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

final class ChargerCommandResource extends Resource
{
    protected static ?string $model = ChargerCommand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Command diagnostics';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Command')->copyable()->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('session_id')->label('Session')->copyable(),
                TextColumn::make('charge_point_identity')->label('Charger')->searchable(),
                TextColumn::make('correlation_id')->label('Correlation')->copyable(),
                TextColumn::make('expires_at')->dateTime()->sortable(),
                TextColumn::make('error_code')->placeholder('None'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(ChargerCommandState::cases())->mapWithKeys(
                        fn (ChargerCommandState $state): array => [
                            $state->value => str($state->value)->headline()->toString(),
                        ],
                    )->all()),
                SelectFilter::make('type')
                    ->options(collect(ChargerCommandType::cases())->mapWithKeys(
                        fn (ChargerCommandType $type): array => [
                            $type->value => str($type->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'id' => 'Command',
                    'type' => 'Type',
                    'state' => 'State',
                    'session_id' => 'Session',
                    'charge_point_identity' => 'Charger',
                    'correlation_id' => 'Correlation identifier',
                    'expires_at' => 'Expires at (UTC)',
                    'error_code' => 'Error code',
                    'safe_message' => 'Safe message',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListChargerCommands::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::ChargingSessionView);
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

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }
        $sessions = app(AccessibleChargingSessionsQuery::class)->for($user)->select('charging_sessions.id');

        return parent::getEloquentQuery()->whereIn('session_id', $sessions);
    }
}
