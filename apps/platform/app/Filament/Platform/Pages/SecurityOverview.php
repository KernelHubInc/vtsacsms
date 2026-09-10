<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Foundation\Audit\TenantAuditReader;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

final class SecurityOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected static ?string $navigationLabel = 'Security overview';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.platform.pages.security-overview';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::AuditView);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['auditEvents' => app(TenantAuditReader::class)->recent(10)];
    }
}
