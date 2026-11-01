<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Invitations;

use App\Filament\Platform\Resources\Invitations\Pages\ListUserInvitations;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class UserInvitationResource extends Resource
{
    protected static ?string $model = UserInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected static ?string $navigationLabel = 'Invitations';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('purpose', 'tenant')->with(['organization', 'role']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user() instanceof User && app(AuthorizationService::class)->allows(auth()->user(), PermissionKey::InvitationManage);
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

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->searchable(),
            TextColumn::make('organization.name')->placeholder('Tenant-wide'),
            TextColumn::make('role.name'),
            TextColumn::make('scope_type')->badge(),
            TextColumn::make('status')->state(fn (UserInvitation $record): string => $record->accepted_at !== null ? 'Accepted' : ($record->revoked_at !== null ? 'Revoked' : ($record->expires_at->isPast() ? 'Expired' : 'Pending')))->badge(),
            TextColumn::make('expires_at')->label('Expires (UTC)')->dateTime(),
        ])->recordActions([
            RecordViewAction::make(['email' => 'Email', 'organization.name' => 'Organization', 'role.name' => 'Role', 'scope_type' => 'Scope', 'scope_id' => 'Scope ID', 'expires_at' => 'Expires (UTC)', 'accepted_at' => 'Accepted (UTC)', 'revoked_at' => 'Revoked (UTC)']),
            Action::make('resend')->label('Send new link')->requiresConfirmation()
                ->modalDescription('The previous link will stop working. A new seven-day invitation will be queued to the same email address.')
                ->visible(fn (UserInvitation $record): bool => $record->accepted_at === null && $record->revoked_at === null)
                ->action(function (UserInvitation $record): void {
                    abort_unless(self::canViewAny(), 403);
                    app(InvitationService::class)->resend(auth()->user(), $record);
                    Notification::make()->title('New invitation queued')->success()->send();
                }),
            Action::make('revoke')->color('danger')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->visible(fn (UserInvitation $record): bool => $record->accepted_at === null && $record->revoked_at === null)
                ->action(function (UserInvitation $record, array $data): void {
                    abort_unless(self::canViewAny(), 403);
                    app(InvitationService::class)->revoke(auth()->user(), $record, $data['reason']);
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListUserInvitations::route('/')];
    }
}
