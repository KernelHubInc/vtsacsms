<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Roles;

use App\Filament\Platform\Resources\Roles\Pages\CreateRole;
use App\Filament\Platform\Resources\Roles\Pages\EditRole;
use App\Filament\Platform\Resources\Roles\Pages\ListRoles;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

final class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role details')
                ->description('Custom roles apply to everyone assigned to them. Other assigned roles can also grant access.')
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('key')->required()->maxLength(80)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)),
                ])->columns(2)->columnSpanFull(),
            Section::make('Modules and functions')
                ->key('role_modules')
                ->description('Expand a module and choose individual functions. Remove module clears all its grants. Refunds, remote commands, approvals, publishing and exports require explicit selection. Only grant authority you hold; keep portal access for users who need to sign in.')
                ->schema(RolePermissionFields::schema())
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('key')->copyable()->searchable(),
            IconColumn::make('is_system')->boolean(),
            TextColumn::make('assignments_count')->counts('assignments')->label('Assignments'),
        ])->recordActions([
            RecordViewAction::make([
                'name' => 'Name',
                'key' => 'Key',
                'is_system' => 'System role',
                'assignments_count' => 'Assignments',
                'created_at' => 'Created at (UTC)',
                'updated_at' => 'Updated at (UTC)',
            ]),
            EditAction::make()->visible(fn (Role $record): bool => ! $record->is_system),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::RoleView);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::RoleManage);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record instanceof Role
            && ! $record->is_system
            && $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::RoleManage);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
