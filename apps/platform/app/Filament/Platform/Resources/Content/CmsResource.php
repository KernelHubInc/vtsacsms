<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Shared\Actions\AuditedCreateAction;
use App\Filament\Shared\Actions\AuditedEditAction;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

abstract class CmsResource extends Resource
{
    protected static string|UnitEnum|null $navigationGroup = 'Content studio';

    public static function canViewAny(): bool
    {
        return self::allows(PermissionKey::CmsEdit);
    }

    public static function canCreate(): bool
    {
        return self::allows(PermissionKey::CmsEdit);
    }

    public static function canEdit(Model $record): bool
    {
        return self::allows(PermissionKey::CmsEdit);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canPublish(): bool
    {
        return self::allows(PermissionKey::CmsPublish);
    }

    public static function auditedCreateAction(): CreateAction
    {
        return AuditedCreateAction::make('cms.content.created', self::auditTargetType());
    }

    public static function auditedEditAction(): EditAction
    {
        return AuditedEditAction::make('cms.content.updated', self::auditTargetType());
    }

    private static function auditTargetType(): string
    {
        return 'cms_'.Str::snake(class_basename(static::getModel()));
    }

    private static function allows(PermissionKey $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->holdsAnywhere($user, $permission);
    }
}
