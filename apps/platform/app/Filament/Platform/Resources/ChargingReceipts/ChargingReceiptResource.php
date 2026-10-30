<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\ChargingReceipts;

use App\Filament\Platform\Resources\ChargingReceipts\Pages\ListChargingReceipts;
use App\Models\User;
use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class ChargingReceiptResource extends Resource
{
    protected static ?string $model = ChargingEmailDelivery::class;

    protected static ?string $slug = 'charging-receipts';

    protected static ?string $navigationLabel = 'Charging receipts';

    protected static ?string $pluralModelLabel = 'Charging receipts';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('session_id')->label('Session reference')->searchable()->copyable()->wrap(),
            TextColumn::make('delivery_status')->label('Email status')
                ->state(fn (ChargingEmailDelivery $record): string => self::statusLabel($record))->badge()
                ->color(fn (ChargingEmailDelivery $record): string => match ($record->deliveryStatus()) {
                    'accepted', 'sent_legacy' => 'success', 'retrying' => 'danger', default => 'warning',
                }),
            TextColumn::make('attempt_count')->label('Attempts')->numeric()->sortable(),
            TextColumn::make('created_at')->label('Queued (UTC)')->dateTime(timezone: 'UTC')->sortable(),
            TextColumn::make('last_attempt_at')->label('Last attempt (UTC)')->dateTime(timezone: 'UTC')->placeholder('Not recorded')->sortable(),
            TextColumn::make('sent_at')->label('Sent (UTC)')->dateTime(timezone: 'UTC')->placeholder('Not sent')->sortable(),
            TextColumn::make('last_error_code')->label('Last issue')->formatStateUsing(self::errorLabel(...))->placeholder('None recorded')->wrap(),
        ])->filters([
            SelectFilter::make('delivery_status')->label('Email status')->options([
                'sent' => 'Sent / accepted', 'queued' => 'Queued', 'retrying' => 'Retry pending', 'sending' => 'Sending',
            ])->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'sent' => $query->whereNotNull('sent_at'),
                    'queued' => $query->whereNull('sent_at')->where('attempt_count', 0),
                    'sending' => $query->whereNull('sent_at')->where('claimed_until', '>', now('UTC')),
                    'retrying' => $query->whereNull('sent_at')->where('attempt_count', '>', 0)
                        ->where(fn (Builder $q): Builder => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now('UTC'))),
                    default => $query,
                };
            }),
        ])->recordActions([
            ViewAction::make()->schema([
                TextEntry::make('session_id')->label('Session reference')->copyable(),
                TextEntry::make('subject_id')->label('Driver reference')->copyable(),
                TextEntry::make('attempt_count')->label('Attempts'),
                TextEntry::make('mailer')->placeholder('Not recorded'),
                TextEntry::make('message_id')->label('Mail message reference')->copyable()->placeholder('Not recorded')->columnSpanFull(),
                TextEntry::make('last_error_code')->label('Last issue')->formatStateUsing(self::errorLabel(...))->placeholder('None recorded'),
                TextEntry::make('available_at')->label('Next retry (UTC)')->dateTime(timezone: 'UTC')
                    ->visible(fn (ChargingEmailDelivery $record): bool => $record->sent_at === null),
            ])->modalDescription('Accepted means the mail server accepted the message. Inbox, spam placement and later bounces are not confirmed here. Older sent records do not contain a message reference.'),
        ])->defaultSort('created_at', 'desc')->poll('30s');
    }

    public static function statusLabel(ChargingEmailDelivery $record): string
    {
        return match ($record->deliveryStatus()) {
            'accepted' => 'Mail server accepted', 'sent_legacy' => 'Sent (legacy record)',
            'sending' => 'Sending', 'retrying' => 'Retry pending', default => 'Queued',
        };
    }

    public static function errorLabel(string $state): string
    {
        return match ($state) {
            'recipient_unavailable' => 'Driver email must be active and verified',
            'mailer_not_delivering' => 'External email transport is not configured',
            'send_cancelled' => 'Send cancelled before submission',
            'mail_transport_failed' => 'Mail server submission failed',
            'receipt_generation_failed' => 'Receipt preparation or submission failed',
            default => 'Delivery failed; investigate using the session reference',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListChargingReceipts::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::AuditView);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return self::canViewAny() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny() && self::getEloquentQuery()->whereKey($record->getKey())->exists();
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
