<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\PaymentIntents;

use App\Filament\Operator\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Charging\Application\AccessibleChargingSessionsQuery;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\PaymentIntentState;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PaymentIntentResource extends Resource
{
    protected static ?string $model = PaymentIntent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payment intents';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Intent')->copyable()->searchable(), TextColumn::make('state')->badge()->sortable(),
            TextColumn::make('amount_requested_minor')->label('Requested minor')->numeric(), TextColumn::make('amount_authorized_minor')->label('Authorized minor')->numeric(),
            TextColumn::make('amount_captured_minor')->label('Captured minor')->numeric(), TextColumn::make('amount_refunded_minor')->label('Refunded minor')->numeric(),
            TextColumn::make('currency'), TextColumn::make('provider_intent_reference')->label('Provider reference')->copyable()->placeholder('Pending'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(PaymentIntentState::cases())->mapWithKeys(
                        fn (PaymentIntentState $state): array => [
                            $state->value => str($state->value)->headline()->toString(),
                        ],
                    )->all()),
            ])
            ->recordActions([
                RecordViewAction::make([
                    'id' => 'Intent',
                    'state' => 'State',
                    'amount_requested_minor' => 'Requested (minor units)',
                    'amount_authorized_minor' => 'Authorized (minor units)',
                    'amount_captured_minor' => 'Captured (minor units)',
                    'amount_refunded_minor' => 'Refunded (minor units)',
                    'currency' => 'Currency',
                    'provider_intent_reference' => 'Provider reference',
                    'billable_type' => 'Billable type',
                    'billable_id' => 'Billable identifier',
                    'created_at' => 'Created at (UTC)',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentIntents::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::PaymentView);
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
            ? $query
                ->where('billable_type', ChargingSession::class)
                ->whereIn('billable_id', app(AccessibleChargingSessionsQuery::class)->for($user)->select('id'))
            : $query->whereRaw('1 = 0');
    }
}
