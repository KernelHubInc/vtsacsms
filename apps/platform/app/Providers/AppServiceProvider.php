<?php

declare(strict_types=1);

namespace App\Providers;

use App\Foundation\Features\FeatureFlags;
use App\Models\User;
use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Assets\Application\EloquentAssetMaintenanceContract;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Billing\Application\Accounting\AccountingExportAdapter;
use App\Modules\Billing\Application\EInvoicing\BirElectronicInvoicingAdapter;
use App\Modules\Billing\Infrastructure\FakeAccountingExportAdapter;
use App\Modules\Billing\Infrastructure\UnconfiguredBirElectronicInvoicingAdapter;
use App\Modules\Charging\Application\Contracts\FinalizedChargeDetailRecordQuery;
use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Infrastructure\EloquentFinalizedChargeDetailRecordQuery;
use App\Modules\Charging\Infrastructure\HttpGatewayCommandClient;
use App\Modules\Identity\Application\Contracts\MfaChallengeProvider;
use App\Modules\Identity\Application\Contracts\MobileVerificationSender;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Identity\Infrastructure\FakeLocalMobileVerificationSender;
use App\Modules\Identity\Infrastructure\UnavailableMfaChallengeProvider;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ReorderPoint;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Maintenance\Application\Contracts\FaultObservationContract;
use App\Modules\Maintenance\Application\FaultAutomationService;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Infrastructure\EloquentPaymentFactsQuery;
use App\Modules\Procurement\Application\Contracts\PurchaseOrderReceiptContract;
use App\Modules\Procurement\Application\Contracts\SupplierReturnContract;
use App\Modules\Procurement\Application\Contracts\VendorInvoiceAccountingAdapter;
use App\Modules\Procurement\Application\EloquentPurchaseOrderReceiptContract;
use App\Modules\Procurement\Application\EloquentSupplierReturnContract;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use App\Modules\Procurement\Infrastructure\FakeVendorInvoiceAccountingAdapter;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Policies\AdjustmentRequestPolicy;
use App\Policies\ChargingSessionPolicy;
use App\Policies\ChargingStationPolicy;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\ReorderPointPolicy;
use App\Policies\SitePolicy;
use App\Policies\StockTransferPolicy;
use App\Policies\SupportTicketPolicy;
use App\Policies\TariffPolicy;
use App\Policies\VendorInvoicePolicy;
use App\Policies\WarehousePolicy;
use App\Policies\WorkOrderPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FeatureFlags::class);
        $this->app->scoped(CurrentTenant::class);
        $this->app->bind(MobileVerificationSender::class, FakeLocalMobileVerificationSender::class);
        $this->app->bind(MfaChallengeProvider::class, UnavailableMfaChallengeProvider::class);
        $this->app->bind(GatewayCommandClient::class, HttpGatewayCommandClient::class);
        $this->app->bind(FinalizedChargeDetailRecordQuery::class, EloquentFinalizedChargeDetailRecordQuery::class);
        $this->app->bind(PaymentFactsQuery::class, EloquentPaymentFactsQuery::class);
        $this->app->bind(AccountingExportAdapter::class, FakeAccountingExportAdapter::class);
        $this->app->bind(BirElectronicInvoicingAdapter::class, UnconfiguredBirElectronicInvoicingAdapter::class);
        $this->app->bind(PurchaseOrderReceiptContract::class, EloquentPurchaseOrderReceiptContract::class);
        $this->app->bind(SupplierReturnContract::class, EloquentSupplierReturnContract::class);
        $this->app->bind(VendorInvoiceAccountingAdapter::class, FakeVendorInvoiceAccountingAdapter::class);
        $this->app->bind(AssetMaintenanceContract::class, EloquentAssetMaintenanceContract::class);
        $this->app->bind(FaultObservationContract::class, FaultAutomationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(FeatureFlags::class)->assertProductionSafe();
        Sanctum::usePersonalAccessTokenModel(MobileAccessToken::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(ChargingStation::class, ChargingStationPolicy::class);
        Gate::policy(ChargingSession::class, ChargingSessionPolicy::class);
        Gate::policy(Tariff::class, TariffPolicy::class);
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(VendorInvoice::class, VendorInvoicePolicy::class);
        Gate::policy(InventoryItem::class, InventoryItemPolicy::class);
        Gate::policy(ReorderPoint::class, ReorderPointPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(AdjustmentRequest::class, AdjustmentRequestPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);

        RateLimiter::for('auth.login', static fn (Request $request): array => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perHour(30)->by((string) $request->ip()),
        ]);
        RateLimiter::for('auth.recovery', static fn (Request $request): array => [
            Limit::perMinute(3)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);
        RateLimiter::for('auth.verify', static fn (Request $request): array => [
            Limit::perMinute(3)->by(($request->user() instanceof User
                ? (string) $request->user()->public_id
                : (string) $request->ip())),
        ]);
        RateLimiter::for('charging.commands', static fn (Request $request): array => [
            Limit::perMinute(10)->by(($request->user() instanceof User
                ? (string) $request->user()->public_id
                : (string) $request->ip())),
        ]);
        RateLimiter::for('payment.webhooks', static fn (Request $request): array => [
            Limit::perMinute(120)->by((string) $request->ip()),
        ]);
    }
}
