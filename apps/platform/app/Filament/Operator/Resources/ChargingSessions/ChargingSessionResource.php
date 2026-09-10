<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\ChargingSessions;

use App\Filament\Operator\Resources\ChargingSessions\Pages\ListChargingSessions;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Application\RemoteStopService;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

final class ChargingSessionResource extends Resource
{
    protected static ?string $model = ChargingSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Session diagnostics';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Session')->copyable()->searchable(),
                TextColumn::make('state')->badge()->sortable(),
                TextColumn::make('charge_point_identity')->label('Charger')->searchable(),
                TextColumn::make('protocol_transaction_id')->label('Transaction')->placeholder('Awaiting evidence'),
                TextColumn::make('energy_wh')->label('Energy (Wh)')->numeric()->sortable(),
                TextColumn::make('estimated_cost_minor')->label('Estimated minor')->numeric()->placeholder('—'),
                TextColumn::make('currency')->placeholder('Unrated'),
                TextColumn::make('anomaly_flags')->label('Anomalies')->formatStateUsing(
                    fn (mixed $state): string => is_array($state) && $state !== [] ? implode(', ', $state) : 'None',
                )->wrap(),
                TextColumn::make('requested_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(ChargingSessionState::cases())->mapWithKeys(
                        fn (ChargingSessionState $state): array => [
                            $state->value => str($state->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'id' => 'Session',
                    'state' => 'State',
                    'site.name' => 'Site',
                    'charge_point_identity' => 'Charger',
                    'protocol_transaction_id' => 'Protocol transaction',
                    'energy_wh' => 'Energy (Wh)',
                    'estimated_cost_minor' => 'Estimated cost (minor units)',
                    'final_cost_minor' => 'Final cost (minor units)',
                    'currency' => 'Currency',
                    'requested_at' => 'Requested at (UTC)',
                    'started_at' => 'Started at (UTC)',
                    'ended_at' => 'Ended at (UTC)',
                ]),
                Action::make('remoteStop')
                    ->label('Remote stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ChargingSession $record): bool => self::canRemoteStop($record))
                    ->action(function (ChargingSession $record): void {
                        abort_unless(self::canRemoteStop($record), 403);
                        app(RemoteStopService::class)->request($record, 'portal:'.Str::ulid());
                        Notification::make()
                            ->title('Remote stop requested')
                            ->body('The session remains in stopping state until charger evidence is received.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListChargingSessions::route('/')];
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
        $query = parent::getEloquentQuery();

        return $user instanceof User
            ? $query->whereIn('charging_sessions.id', app(AccessibleChargingSessionsQuery::class)->for($user)->select('charging_sessions.id'))
            : $query->whereRaw('1 = 0');
    }

    private static function canRemoteStop(ChargingSession $session): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && in_array($session->state, [
                ChargingSessionState::Starting,
                ChargingSessionState::Charging,
                ChargingSessionState::SuspendedByEv,
                ChargingSessionState::SuspendedByEvse,
            ], true)
            && app(AuthorizationService::class)->allows(
                $user,
                PermissionKey::ChargingRemoteCommand,
                new ResourceScope(ScopeType::Site, $session->site_id),
            );
    }
}
