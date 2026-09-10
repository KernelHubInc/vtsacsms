<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Operator\Resources\ChargerCommands\ChargerCommandResource;
use App\Filament\Operator\Resources\ChargingSessions\ChargingSessionResource;
use App\Filament\Operator\Resources\ChargingStations\ChargingStationResource;
use App\Filament\Operator\Resources\Connectors\ConnectorResource;
use App\Filament\Operator\Resources\ConnectorStatuses\ConnectorStatusResource;
use App\Filament\Operator\Resources\CountPlans\CountPlanResource;
use App\Filament\Operator\Resources\Evses\EvseResource;
use App\Filament\Operator\Resources\FinanceReviews\FinanceReviewResource;
use App\Filament\Operator\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Operator\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use App\Filament\Operator\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Operator\Resources\Invoices\InvoiceResource;
use App\Filament\Operator\Resources\MaintenanceIncidents\MaintenanceIncidentResource;
use App\Filament\Operator\Resources\Memberships\MembershipResource;
use App\Filament\Operator\Resources\OperatingHours\OperatingHourResource;
use App\Filament\Operator\Resources\PaymentIntents\PaymentIntentResource;
use App\Filament\Operator\Resources\PreventivePlans\PreventivePlanResource;
use App\Filament\Operator\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Operator\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Operator\Resources\ReconciliationLines\ReconciliationLineResource;
use App\Filament\Operator\Resources\ReorderPoints\ReorderPointResource;
use App\Filament\Operator\Resources\SessionReviews\SessionReviewResource;
use App\Filament\Operator\Resources\SettlementBatches\SettlementBatchResource;
use App\Filament\Operator\Resources\Sites\SiteResource;
use App\Filament\Operator\Resources\StockMovements\StockMovementResource;
use App\Filament\Operator\Resources\StockTransfers\StockTransferResource;
use App\Filament\Operator\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Operator\Resources\Tariffs\TariffResource;
use App\Filament\Operator\Resources\VendorInvoices\VendorInvoiceResource;
use App\Filament\Operator\Resources\Warehouses\WarehouseResource;
use App\Filament\Operator\Resources\WorkOrders\WorkOrderResource;
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

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Power Solutions Administration')
            ->brandLogo(asset('branding/power-solutions-logo-horizontal-dark.svg'))
            ->darkModeBrandLogo(asset('branding/power-solutions-logo-horizontal-dark.svg'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('branding/power-solutions-favicon.png'))
            ->navigationGroups(['Charging network', 'Maintenance', 'Procurement', 'Inventory', 'Finance', 'Support', 'Reports', 'Content studio', 'Platform governance', 'Identity and audit'])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'danger' => Color::hex('#D64555'),
                'gray' => Color::hex('#66758A'),
                'info' => Color::hex('#2589BE'),
                'primary' => Color::hex('#12366B'),
                'success' => Color::hex('#18A978'),
                'warning' => Color::hex('#A66400'),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->resources([
                SiteResource::class,
                ChargingStationResource::class,
                EvseResource::class,
                ConnectorResource::class,
                OperatingHourResource::class,
                ConnectorStatusResource::class,
                TariffResource::class,
                ChargingSessionResource::class,
                ChargerCommandResource::class,
                PaymentIntentResource::class,
                InvoiceResource::class,
                MembershipResource::class,
                FinanceReviewResource::class,
                SessionReviewResource::class,
                ReconciliationLineResource::class,
                SettlementBatchResource::class,
                PurchaseRequestResource::class,
                PurchaseOrderResource::class,
                VendorInvoiceResource::class,
                InventoryItemResource::class,
                WarehouseResource::class,
                GoodsReceiptResource::class,
                StockMovementResource::class,
                StockTransferResource::class,
                InventoryAdjustmentResource::class,
                CountPlanResource::class,
                ReorderPointResource::class,
                WorkOrderResource::class,
                MaintenanceIncidentResource::class,
                PreventivePlanResource::class,
                SupportTicketResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
