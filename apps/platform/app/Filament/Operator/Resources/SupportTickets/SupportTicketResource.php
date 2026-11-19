<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\SupportTickets;

use App\Filament\Operator\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Notifications\Application\SupportEmailStatusQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Support\Application\AccessibleSupportTickets;
use App\Modules\Support\Application\SupportEmailQuery;
use App\Modules\Support\Application\SupportIntake;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\Models\SupportTicket;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            TextColumn::make('source')->label('Channel')->badge(),
            TextColumn::make('session_id')->label('Session reference')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('category')->formatStateUsing(fn (string $state): string => SupportIntake::CATEGORIES[$state] ?? Str::headline($state)),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('customer_email_state')->label('Latest email')->badge()->placeholder('No email queued')
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'accepted' => 'Accepted by mail server', 'failed' => 'Needs attention', default => Str::headline($state)
                })
                ->color(fn (?string $state): string => match ($state) {
                    'accepted' => 'success', 'failed' => 'danger', 'retrying' => 'warning', default => 'gray'
                }),
            TextColumn::make('messages_count')->counts('messages')->label('Responses'),
            TextColumn::make('escalation_level')->label('Escalation')->numeric(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('category')->options(fn (): array => [...SupportIntake::CATEGORIES, ...(self::canViewAccountDeletion() ? [SupportTicket::ACCOUNT_DELETION => 'Account deletion'] : [])]),
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
                'source' => 'Submission channel',
                'session_id' => 'Session reference',
                'status' => 'Status',
                'priority' => 'Priority',
                'escalation_level' => 'Escalation level',
                'created_at' => 'Created at (UTC)',
                'updated_at' => 'Updated at (UTC)',
            ]),
            Action::make('conversation')->label('Conversation')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->modalContent(fn (SupportTicket $record) => view('filament.operator.support-conversation', [
                    'ticket' => $record, 'messages' => $record->messages()->with('author:id,name')->latest('id')->limit(100)->get()->reverse(),
                ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('revealContact')->label('View contact')->requiresConfirmation()
                ->visible(fn (SupportTicket $record): bool => app(AuthorizationService::class)->allows(self::actor(), PermissionKey::SupportSensitiveReveal,
                    $record->site_id === null ? ResourceScope::tenant() : new ResourceScope(ScopeType::Site, $record->site_id)))
                ->action(function (SupportTicket $record): void {
                    Gate::authorize('view', $record);
                    abort_unless(app(AuthorizationService::class)->allows(self::actor(), PermissionKey::SupportSensitiveReveal,
                        $record->site_id === null ? ResourceScope::tenant() : new ResourceScope(ScopeType::Site, $record->site_id)), 403);
                    $details = app(SupportEmailQuery::class)->message((string) $record->getKey(), null);
                    app(AuditRecorder::class)->record(new AuditEntry('support.contact.revealed', 'support_ticket', (string) $record->getKey(), AuditResult::Succeeded));
                    Notification::make()->title('Verified contact')->body($details['email'] ?? 'No current verified email is available.')->persistent()->send();
                }),
            Action::make('changeStatus')->label('Update status')->requiresConfirmation()
                ->visible(fn (SupportTicket $record): bool => config('support.enabled') && $record->category !== SupportTicket::ACCOUNT_DELETION && Gate::allows('update', $record))
                ->schema([Select::make('status')->options(['open' => 'Open', 'in_progress' => 'In progress', 'pending_customer' => 'Pending customer', 'resolved' => 'Resolved', 'closed' => 'Closed'])->required()
                    ->helperText('Send a public reply to notify the customer of an outcome.')])
                ->action(fn (SupportTicket $record, array $data) => app(SupportTicketService::class)->changeStatus(self::actor(), $record, $data['status'])),
            Action::make('respond')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->schema([
                    Textarea::make('body')->label('Response')->required()->maxLength(5000),
                    Checkbox::make('is_internal')->label('Internal note')->helperText('Internal notes stay in the staff inbox and are never emailed.'),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::ulid())->required(),
                ])
                ->visible(fn (SupportTicket $record): bool => Gate::allows('update', $record))
                ->action(function (SupportTicket $record, array $data): void {
                    app(SupportTicketService::class)->respond(
                        self::actor(),
                        $record,
                        (string) $data['body'],
                        (bool) ($data['is_internal'] ?? false),
                        $data['idempotency_key'],
                    );
                    Notification::make()->title(($data['is_internal'] ?? false) ? 'Internal note saved' : (config('support.enabled') ? 'Reply saved and email queued' : 'Response saved'))->success()->send();
                }),
            Action::make('escalate')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (SupportTicket $record): bool => Gate::allows('update', $record) && $record->status !== 'resolved')
                ->action(function (SupportTicket $record): void {
                    app(SupportTicketService::class)->escalate(self::actor(), $record);
                    Notification::make()->title('Ticket escalated')->warning()->send();
                }),
            Action::make('resolveAccountDeletion')
                ->label('Mark deletion request resolved')
                ->requiresConfirmation()
                ->modalDescription('Use this after the approved privacy process has addressed the request and communicated the outcome. This action only closes the support ticket.')
                ->visible(fn (SupportTicket $record): bool => $record->category === SupportTicket::ACCOUNT_DELETION
                    && ! in_array($record->status, ['resolved', 'closed'], true) && Gate::allows('update', $record))
                ->action(function (SupportTicket $record): void {
                    app(SupportTicketService::class)->resolveAccountDeletion(self::actor(), $record);
                    Notification::make()->title('Deletion request resolved')->success()->send();
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

    /** @return Builder<SupportTicket> */
    public static function getEloquentQuery(): Builder
    {
        return app(SupportEmailStatusQuery::class)->withLatestStatus(app(AccessibleSupportTickets::class)->for(self::actor())->with('site'));
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function canViewAccountDeletion(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::SupportView);
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
