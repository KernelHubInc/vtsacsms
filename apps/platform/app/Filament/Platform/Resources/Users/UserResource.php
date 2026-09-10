<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Users;

use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Identity\Application\AccountLifecycleService;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->copyable(),
            IconColumn::make('email_verified_at')->label('Verified')->boolean(),
            TextColumn::make('activated_at')->dateTime()->placeholder('Not activated'),
            TextColumn::make('disabled_at')->dateTime()->placeholder('Active'),
        ])->filters([
            TernaryFilter::make('disabled_at')
                ->label('Account status')
                ->nullable()
                ->trueLabel('Suspended')
                ->falseLabel('Active')
                ->placeholder('All accounts'),
        ])->recordActions([
            RecordViewAction::make([
                'public_id' => 'Public identifier',
                'name' => 'Name',
                'email' => 'Email',
                'email_verified_at' => 'Email verified at (UTC)',
                'mobile_verified_at' => 'Mobile verified at (UTC)',
                'activated_at' => 'Activated at (UTC)',
                'disabled_at' => 'Suspended at (UTC)',
                'last_login_at' => 'Last login at (UTC)',
            ]),
            Action::make('activate')
                ->visible(fn (User $record): bool => self::canManageAccounts()
                    && ($record->activated_at === null || $record->disabled_at !== null))
                ->requiresConfirmation()
                ->action(fn (User $record) => app(AccountLifecycleService::class)->activate(auth()->user(), $record)),
            Action::make('suspend')
                ->color('danger')
                ->visible(fn (User $record): bool => self::canManageAccounts() && $record->disabled_at === null)
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (User $record, array $data) => app(AccountLifecycleService::class)->suspend(auth()->user(), $record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('memberships');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::MembershipView);
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

    private static function canManageAccounts(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::MembershipManage);
    }
}
