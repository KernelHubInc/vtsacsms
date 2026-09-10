<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Memberships;

use App\Filament\Operator\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\MembershipLifecycleService;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'People and access';

    protected static ?string $navigationLabel = 'Operator users';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('User')->searchable()->sortable(),
            TextColumn::make('user.email')->label('Email')->searchable()->copyable(),
            TextColumn::make('organization.name')->label('Organization')->placeholder('Tenant-wide'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('assignments_count')->counts('assignments')->label('Role grants'),
            TextColumn::make('expires_at')->dateTime()->placeholder('No expiry'),
        ])->recordActions([
            RecordViewAction::make([
                'user.name' => 'User',
                'user.email' => 'Email',
                'organization.name' => 'Organization',
                'status' => 'Status',
                'joined_at' => 'Joined at (UTC)',
                'expires_at' => 'Expires at (UTC)',
                'created_at' => 'Created at (UTC)',
            ]),
            Action::make('activate')
                ->visible(fn (Membership $record): bool => $record->status !== MembershipStatus::Active
                    && self::canManageMemberships())
                ->requiresConfirmation()
                ->action(fn (Membership $record) => app(MembershipLifecycleService::class)->activate(
                    self::actor(),
                    $record,
                    'portal.membership_activate',
                )),
            Action::make('suspend')
                ->color('danger')
                ->visible(fn (Membership $record): bool => $record->status === MembershipStatus::Active
                    && self::canManageMemberships())
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Membership $record, array $data) => app(MembershipLifecycleService::class)->suspend(
                    self::actor(),
                    $record,
                    is_string($data['reason'] ?? null) ? $data['reason'] : 'portal.membership_suspend',
                )),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberships::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::MembershipView);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function actor(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new \LogicException('A panel actor is required.');
        }

        return $user;
    }

    private static function canManageMemberships(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::MembershipManage);
    }
}
