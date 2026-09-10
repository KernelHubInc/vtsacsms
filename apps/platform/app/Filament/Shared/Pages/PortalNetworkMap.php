<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Models\User;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Reporting\Application\PortalDashboardQuery;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

abstract class PortalNetworkMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|UnitEnum|null $navigationGroup = 'Charging network';

    protected static ?string $navigationLabel = 'Network map';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.shared.pages.network-map';

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
            'stations' => app(PortalDashboardQuery::class)->map($user),
            'mapConfig' => app(MapConfigurationResolver::class)->forSurface($this->mapSurface())->toWebArray(),
        ];
    }

    protected function mapSurface(): MapSurface
    {
        return str_contains(static::class, '\\Operator\\')
            ? MapSurface::Operator
            : MapSurface::Admin;
    }
}
