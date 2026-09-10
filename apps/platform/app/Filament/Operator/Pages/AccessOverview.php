<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

final class AccessOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Accessible sites';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.operator.pages.access-overview';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->holdsAnywhere($user, PermissionKey::LocationView);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'sites' => app(AccessibleSitesQuery::class)->for($user)
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'code', 'is_active']),
        ];
    }
}
