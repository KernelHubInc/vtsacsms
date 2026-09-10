<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Integrations\Domain\Models\OutboxEvent;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class IntegrationHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'Platform governance';

    protected static ?string $navigationLabel = 'Integration health';

    protected string $view = 'filament.platform.pages.integration-health';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::IntegrationView);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        DB::select('select 1');
        $maps = app(MapConfigurationResolver::class)->forSurface(MapSurface::Admin);

        return [
            'checks' => [
                ['name' => 'Platform database', 'state' => 'healthy', 'detail' => 'Read query completed'],
                ['name' => 'OCPP command transport', 'state' => config('services.ocpp_gateway.base_url') ? 'configured' : 'not_configured', 'detail' => 'Credentials are never displayed'],
                ['name' => 'Google Maps browser adapter', 'state' => $maps->googleBrowserReady ? 'configured' : 'not_configured', 'detail' => $maps->fallbackActive ? 'OpenStreetMap fallback active' : 'Browser-restricted key presence only'],
                ['name' => 'Object storage', 'state' => config('filesystems.default') ? 'configured' : 'not_configured', 'detail' => (string) config('filesystems.default')],
            ],
            'outboxPending' => OutboxEvent::query()->whereNull('published_at')->count(),
            'outboxFailed' => OutboxEvent::query()->whereNull('published_at')->where('publish_attempts', '>', 3)->count(),
        ];
    }
}
