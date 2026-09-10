<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Permissions;

use App\Filament\Platform\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Shared\Actions\RecordViewAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Permission;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->copyable()->searchable()->sortable(),
            TextColumn::make('context')->badge()->sortable(),
            TextColumn::make('description')->wrap()->searchable(),
        ])->recordActions([
            RecordViewAction::make([
                'key' => 'Permission key',
                'context' => 'Bounded context',
                'description' => 'Description',
            ]),
        ])->defaultSort('context');
    }

    public static function getPages(): array
    {
        return ['index' => ListPermissions::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::RoleView);
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
