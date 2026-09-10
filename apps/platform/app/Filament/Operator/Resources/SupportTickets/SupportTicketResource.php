<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SupportTickets;

use App\Filament\Operator\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('ticket_number')->label('Ticket')->searchable()->copyable(),
            TextColumn::make('subject')->searchable()->wrap(),
            TextColumn::make('site.name')->label('Station')->placeholder('No station'),
            TextColumn::make('requester.email')->label('Requester')->placeholder('Anonymous'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('messages_count')->counts('messages')->label('Responses'),
            TextColumn::make('escalation_level')->label('Escalation')->numeric(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options([
                'open' => 'Open',
                'pending_customer' => 'Pending customer',
                'in_progress' => 'In progress',
                'escalated' => 'Escalated',
                'resolved' => 'Resolved',
                'closed' => 'Closed',
            ]),
            SelectFilter::make('priority')->options([
                'low' => 'Low',
                'normal' => 'Normal',
                'high' => 'High',
                'urgent' => 'Urgent',
            ]),
        ])->recordActions([
            RecordViewAction::make([
                'ticket_number' => 'Ticket',
                'subject' => 'Subject',
                'description' => 'Description',
                'site.name' => 'Station',
                'requester.email' => 'Requester',
                'status' => 'Status',
                'priority' => 'Priority',
                'escalation_level' => 'Escalation level',
                'created_at' => 'Created at (UTC)',
                'updated_at' => 'Updated at (UTC)',
            ]),
            Action::make('respond')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->schema([
                    Textarea::make('body')->label('Response')->required()->maxLength(5000),
                    Checkbox::make('is_internal')->label('Internal note'),
                ])
                ->visible(fn (): bool => self::canManage())
                ->action(function (SupportTicket $record, array $data): void {
                    app(SupportTicketService::class)->respond(
                        self::actor(),
                        $record,
                        (string) $data['body'],
                        (bool) ($data['is_internal'] ?? false),
                    );
                    Notification::make()->title('Response added')->success()->send();
                }),
            Action::make('escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (SupportTicket $record): bool => self::canManage() && $record->status !== 'resolved')
                ->action(function (SupportTicket $record): void {
                    app(SupportTicketService::class)->escalate(self::actor(), $record);
                    Notification::make()->title('Ticket escalated')->warning()->send();
                }),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportTickets::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::SupportView);
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

    private static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::SupportManage);
    }

    private static function actor(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new \LogicException('A support actor is required.');
        }

        return $user;
    }
}
