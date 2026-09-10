<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\EstablishPanelTenantContext;
use App\Http\Middleware\TrackPanelSession;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('operator')
            ->login(Login::class)
            ->brandName('Power Solutions Operator Workspace')
            ->brandLogo(asset('branding/power-solutions-logo-horizontal-dark.svg'))
            ->darkModeBrandLogo(asset('branding/power-solutions-logo-horizontal-dark.svg'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('branding/power-solutions-favicon.png'))
            ->navigationGroups(['Charging network', 'Maintenance', 'Procurement', 'Inventory', 'Finance', 'Support', 'People and access', 'Reports'])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'danger' => Color::hex('#D64555'),
                'gray' => Color::hex('#66758A'),
                'info' => Color::hex('#2589BE'),
                'primary' => Color::hex('#12366B'),
                'success' => Color::hex('#18A978'),
                'warning' => Color::hex('#A66400'),
            ])
            ->discoverResources(
                in: app_path('Filament/Operator/Resources'),
                for: 'App\Filament\Operator\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Operator/Pages'),
                for: 'App\Filament\Operator\Pages',
            )
            ->pages([Dashboard::class])
            ->discoverWidgets(
                in: app_path('Filament/Operator/Widgets'),
                for: 'App\Filament\Operator\Widgets',
            )
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EstablishPanelTenantContext::class,
                TrackPanelSession::class,
            ], isPersistent: true);
    }
}
