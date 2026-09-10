<?php

declare(strict_types=1);

use App\Foundation\Features\Feature;
use App\Http\Controllers\Api\V1\AppConfigurationController;
use App\Http\Controllers\Api\V1\ChargingSessionController;
use App\Http\Controllers\Api\V1\ChargingStationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\IdentityContextController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InventoryCatalogController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MembershipLifecycleController;
use App\Http\Controllers\Api\V1\MobileAuthController;
use App\Http\Controllers\Api\V1\MobileVerificationController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PublicStationSearchController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\RemoteStartController;
use App\Http\Controllers\Api\V1\RemoteStopController;
use App\Http\Controllers\Api\V1\SanctumIdentityController;
use App\Http\Controllers\Api\V1\SessionReviewController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\SiteExportController;
use App\Http\Controllers\Api\V1\SiteReportController;
use App\Http\Controllers\Api\V1\SourcingController;
use App\Http\Controllers\Api\V1\StationDataTransferController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockReservationController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\TariffController;
use App\Http\Controllers\Api\V1\TariffVersionController;
use App\Http\Controllers\Api\V1\VendorInvoiceController;
use App\Http\Controllers\Api\V1\WorkOrderPartsController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureVerifiedIdentity;
use App\Http\Middleware\EstablishSanctumTenantContext;
use App\Http\Middleware\EstablishTenantContext;
use App\Http\Middleware\RequireEnabledFeature;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireSanctumPermission;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::get('/v1/public/stations', PublicStationSearchController::class)
    ->middleware('throttle:60,1')->name('api.v1.public.stations.index');

Route::get('/v1/app/config', AppConfigurationController::class)
    ->middleware('throttle:60,1')->name('api.v1.app.config');

Route::post('/v1/webhooks/payments/{tenant}/{configuration}', PaymentWebhookController::class)
    ->whereUlid('tenant')->whereUlid('configuration')
    ->middleware([
        RequireEnabledFeature::class.':'.Feature::RealPayments->value,
        'throttle:payment.webhooks',
    ])->name('api.v1.webhooks.payments');

Route::prefix('v1/auth')->group(function (): void {
    Route::post('/register', RegistrationController::class)
        ->middleware('throttle:auth.login')->name('api.v1.auth.register');
    Route::post('/login', [MobileAuthController::class, 'login'])
        ->middleware('throttle:auth.login')->name('api.v1.auth.login');
    Route::post('/forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:auth.recovery')->name('api.v1.auth.password.forgot');
    Route::post('/reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:auth.recovery')->name('api.v1.auth.password.reset');
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:auth.recovery')->name('api.v1.auth.invitations.accept');
});

Route::prefix('v1')->middleware([
    'auth:sanctum',
    EstablishSanctumTenantContext::class,
])->group(function (): void {
    Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:auth.verify')->name('api.v1.auth.email.send');
    Route::get('/auth/email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:auth.verify'])->name('api.v1.auth.email.verify');

    Route::middleware(EnsureVerifiedIdentity::class)->group(function (): void {
        Route::get('/me', SanctumIdentityController::class)->name('api.v1.me');
        Route::post('/auth/mobile/verification', [MobileVerificationController::class, 'start'])
            ->middleware('throttle:auth.verify')->name('api.v1.auth.mobile.start');
        Route::post('/auth/mobile/verify', [MobileVerificationController::class, 'verify'])
            ->middleware('throttle:auth.verify')->name('api.v1.auth.mobile.verify');

        Route::get('/devices', [DeviceController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::SessionView->value)
            ->name('api.v1.devices.index');
        Route::delete('/devices', [DeviceController::class, 'destroyAll'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::SessionRevoke->value)
            ->name('api.v1.devices.destroy-all');
        Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::SessionRevoke->value)
            ->name('api.v1.devices.destroy');

        Route::post('/invitations', [InvitationController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InvitationManage->value)
            ->name('api.v1.invitations.store');
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InvitationManage->value)
            ->name('api.v1.invitations.destroy');
        Route::post('/memberships/{membership}/suspend', [MembershipLifecycleController::class, 'suspend'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MembershipManage->value)
            ->name('api.v1.memberships.suspend');
        Route::post('/memberships/{membership}/activate', [MembershipLifecycleController::class, 'activate'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MembershipManage->value)
            ->name('api.v1.memberships.activate');

        Route::get('/sites', [SiteController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::LocationView->value)
            ->name('api.v1.sites.index');
        Route::get('/sites/{site}', [SiteController::class, 'show'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::LocationView->value)
            ->name('api.v1.sites.show');
        Route::get('/reports/sites', SiteReportController::class)
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ReportingView->value)
            ->name('api.v1.reports.sites');
        Route::get('/exports/sites', SiteExportController::class)
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ReportingExport->value)
            ->name('api.v1.exports.sites');

        Route::get('/stations', [ChargingStationController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetView->value)
            ->name('api.v1.stations.index');
        Route::get('/stations/{station}', [ChargingStationController::class, 'show'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetView->value)
            ->name('api.v1.stations.show');
        Route::post('/stations', [ChargingStationController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetManage->value)
            ->name('api.v1.stations.store');
        Route::patch('/stations/{station}', [ChargingStationController::class, 'update'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetManage->value)
            ->name('api.v1.stations.update');
        Route::get('/stations-import-template', [StationDataTransferController::class, 'template'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetManage->value)
            ->name('api.v1.stations.import-template');
        Route::post('/stations-imports', [StationDataTransferController::class, 'import'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::AssetManage->value)
            ->name('api.v1.stations.import');
        Route::get('/exports/stations', [StationDataTransferController::class, 'export'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ReportingExport->value)
            ->name('api.v1.exports.stations');

        Route::get('/charging-sessions', [ChargingSessionController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ChargingSessionView->value)
            ->name('api.v1.charging-sessions.index');
        Route::get('/charging-sessions/{session}', [ChargingSessionController::class, 'show'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ChargingSessionView->value)
            ->name('api.v1.charging-sessions.show');
        Route::post('/charging-sessions/remote-start', RemoteStartController::class)
            ->middleware([
                RequireEnabledFeature::class.':'.Feature::RemoteCharging->value,
                RequireSanctumPermission::class.':'.PermissionKey::ChargingRemoteCommand->value,
                'throttle:charging.commands',
            ])->name('api.v1.charging-sessions.remote-start');
        Route::post('/charging-sessions/{session}/remote-stop', RemoteStopController::class)
            ->middleware([
                RequireEnabledFeature::class.':'.Feature::RemoteCharging->value,
                RequireSanctumPermission::class.':'.PermissionKey::ChargingRemoteCommand->value,
                'throttle:charging.commands',
            ])->name('api.v1.charging-sessions.remote-stop');
        Route::post('/charging-sessions/{session}/cancel', [ChargingSessionController::class, 'cancel'])
            ->middleware([
                RequireSanctumPermission::class.':'.PermissionKey::ChargingRemoteCommand->value,
                'throttle:charging.commands',
            ])->name('api.v1.charging-sessions.cancel');
        Route::post('/charging-sessions/{session}/review-resolution', [SessionReviewController::class, 'resolve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ChargingSessionReview->value)
            ->name('api.v1.charging-sessions.review-resolution');

        Route::get('/tariffs', [TariffController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::TariffView->value)
            ->name('api.v1.tariffs.index');
        Route::get('/tariffs/{tariff}', [TariffController::class, 'show'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::TariffView->value)
            ->name('api.v1.tariffs.show');
        Route::post('/tariffs', [TariffController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::TariffManage->value)
            ->name('api.v1.tariffs.store');
        Route::post('/tariffs/{tariff}/versions', [TariffVersionController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::TariffManage->value)
            ->name('api.v1.tariffs.versions.store');
        Route::post('/tariff-versions/{version}/publish', [TariffVersionController::class, 'publish'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::TariffPublish->value)
            ->name('api.v1.tariff-versions.publish');

        Route::get('/purchase-requests', [PurchaseRequestController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementView->value)
            ->name('api.v1.purchase-requests.index');
        Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementView->value)
            ->name('api.v1.purchase-requests.show');
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-requests.store');
        Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-requests.submit');
        Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.purchase-requests.approve');
        Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.purchase-requests.reject');
        Route::post('/purchase-requests/{purchaseRequest}/revision', [PurchaseRequestController::class, 'revise'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-requests.revision');
        Route::post('/purchase-requests/{purchaseRequest}/rfqs', [SourcingController::class, 'createRfq'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-requests.rfqs.store');
        Route::post('/rfqs/{rfq}/quotations', [SourcingController::class, 'recordQuotation'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.rfqs.quotations.store');
        Route::post('/rfqs/{rfq}/comparison', [SourcingController::class, 'prepareComparison'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.rfqs.comparison.store');
        Route::post('/quotation-comparisons/{comparison}/approve', [SourcingController::class, 'approveComparison'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.quotation-comparisons.approve');
        Route::post('/quotation-comparisons/{comparison}/purchase-order', [SourcingController::class, 'createPurchaseOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.quotation-comparisons.purchase-order');
        Route::get('/purchase-orders', [SourcingController::class, 'indexOrders'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementView->value)
            ->name('api.v1.purchase-orders.index');
        Route::get('/purchase-orders/{purchaseOrder}', [SourcingController::class, 'showOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementView->value)
            ->name('api.v1.purchase-orders.show');
        Route::post('/purchase-orders/{purchaseOrder}/submit', [SourcingController::class, 'submitOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-orders.submit');
        Route::post('/purchase-orders/{purchaseOrder}/approve', [SourcingController::class, 'approveOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.purchase-orders.approve');
        Route::post('/purchase-orders/{purchaseOrder}/issue', [SourcingController::class, 'issueOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.purchase-orders.issue');
        Route::get('/vendor-invoices', [VendorInvoiceController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementView->value)
            ->name('api.v1.vendor-invoices.index');
        Route::post('/purchase-orders/{purchaseOrder}/vendor-invoices', [VendorInvoiceController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementManage->value)
            ->name('api.v1.purchase-orders.vendor-invoices.store');
        Route::post('/vendor-invoices/{vendorInvoice}/match', [VendorInvoiceController::class, 'match'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.vendor-invoices.match');
        Route::post('/vendor-invoices/{vendorInvoice}/approve', [VendorInvoiceController::class, 'approve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.vendor-invoices.approve');
        Route::post('/vendor-invoices/{vendorInvoice}/export', [VendorInvoiceController::class, 'export'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::ProcurementApprove->value)
            ->name('api.v1.vendor-invoices.export');

        Route::get('/inventory/items', [InventoryCatalogController::class, 'items'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.items.index');
        Route::get('/inventory/warehouses', [InventoryCatalogController::class, 'warehouses'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.warehouses.index');
        Route::get('/inventory/items/{item}/availability', [InventoryCatalogController::class, 'availability'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.items.availability');
        Route::get('/inventory/movements', [InventoryCatalogController::class, 'movements'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.movements.index');
        Route::get('/inventory/reports/reorder-alerts', [InventoryCatalogController::class, 'reorderAlerts'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.reports.reorder-alerts');
        Route::get('/inventory/import-template', [InventoryCatalogController::class, 'importTemplate'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.import-template');
        Route::post('/inventory/imports', [InventoryCatalogController::class, 'import'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.imports.store');
        Route::get('/inventory/exports/items', [InventoryCatalogController::class, 'export'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.exports.items');
        Route::get('/inventory/goods-receipts', [GoodsReceiptController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.goods-receipts.index');
        Route::post('/inventory/goods-receipts', [GoodsReceiptController::class, 'receive'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.goods-receipts.store');
        Route::post('/inventory/goods-receipts/{goodsReceipt}/inspect', [GoodsReceiptController::class, 'inspect'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.goods-receipts.inspect');
        Route::post('/inventory/goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.goods-receipts.post');
        Route::post('/inventory/goods-receipts/{goodsReceipt}/supplier-return', [GoodsReceiptController::class, 'returnRejected'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.goods-receipts.supplier-return');
        Route::post('/inventory/reservations', [StockReservationController::class, 'reserve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.reservations.store');
        Route::post('/inventory/reservations/{reservation}/release', [StockReservationController::class, 'release'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.reservations.release');
        Route::get('/inventory/transfers', [StockTransferController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.transfers.index');
        Route::post('/inventory/transfers', [StockTransferController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.transfers.store');
        Route::post('/inventory/transfers/{transfer}/submit', [StockTransferController::class, 'submit'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.transfers.submit');
        Route::post('/inventory/transfers/{transfer}/approve', [StockTransferController::class, 'approve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.transfers.approve');
        Route::post('/inventory/transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.transfers.dispatch');
        Route::post('/inventory/transfers/{transfer}/receive', [StockTransferController::class, 'receive'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.transfers.receive');
        Route::get('/inventory/adjustments', [InventoryAdjustmentController::class, 'index'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryView->value)
            ->name('api.v1.inventory.adjustments.index');
        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryAdjust->value)
            ->name('api.v1.inventory.adjustments.store');
        Route::post('/inventory/adjustments/{adjustment}/approve', [InventoryAdjustmentController::class, 'approve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryAdjust->value)
            ->name('api.v1.inventory.adjustments.approve');
        Route::post('/inventory/adjustments/{adjustment}/post', [InventoryAdjustmentController::class, 'post'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryAdjust->value)
            ->name('api.v1.inventory.adjustments.post');
        Route::post('/inventory/count-plans', [StockCountController::class, 'store'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.count-plans.store');
        Route::post('/inventory/count-plans/{countPlan}/start', [StockCountController::class, 'start'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.count-plans.start');
        Route::post('/inventory/count-sheets/{countSheet}/submit', [StockCountController::class, 'submitSheet'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.count-sheets.submit');
        Route::post('/inventory/count-sheets/{countSheet}/recount', [StockCountController::class, 'recount'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.count-sheets.recount');
        Route::post('/inventory/count-plans/{countPlan}/approve', [StockCountController::class, 'approve'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryAdjust->value)
            ->name('api.v1.inventory.count-plans.approve');
        Route::post('/inventory/count-plans/{countPlan}/post', [StockCountController::class, 'post'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryAdjust->value)
            ->name('api.v1.inventory.count-plans.post');
        Route::post('/inventory/work-order-parts/issue', [WorkOrderPartsController::class, 'issue'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.work-order-parts.issue');
        Route::post('/inventory/work-order-parts/return', [WorkOrderPartsController::class, 'returnUnused'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::InventoryOperate->value)
            ->name('api.v1.inventory.work-order-parts.return');

        Route::get('/maintenance/work-orders', [MaintenanceController::class, 'workOrders'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.work-orders.index');
        Route::post('/maintenance/work-orders', [MaintenanceController::class, 'storeWorkOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceDispatch->value)
            ->name('api.v1.maintenance.work-orders.store');
        Route::get('/maintenance/work-orders/{workOrder}', [MaintenanceController::class, 'showWorkOrder'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.work-orders.show');
        Route::post('/maintenance/work-orders/{workOrder}/transitions', [MaintenanceController::class, 'transition'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.work-orders.transitions.store');
        Route::post('/maintenance/work-orders/{workOrder}/assignments', [MaintenanceController::class, 'assign'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceDispatch->value)
            ->name('api.v1.maintenance.work-orders.assignments.store');
        Route::post('/maintenance/work-orders/{workOrder}/checklist/{item}', [MaintenanceController::class, 'checklist'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenancePerform->value)
            ->name('api.v1.maintenance.work-orders.checklist.update');
        Route::post('/maintenance/work-orders/{workOrder}/time-entries', [MaintenanceController::class, 'time'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenancePerform->value)
            ->name('api.v1.maintenance.work-orders.time-entries.store');
        Route::post('/maintenance/work-orders/{workOrder}/resolution', [MaintenanceController::class, 'resolution'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenancePerform->value)
            ->name('api.v1.maintenance.work-orders.resolution.store');
        Route::post('/maintenance/work-orders/{workOrder}/reopen', [MaintenanceController::class, 'reopen'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceDispatch->value)
            ->name('api.v1.maintenance.work-orders.reopen');
        Route::get('/maintenance/incidents', [MaintenanceController::class, 'incidents'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.incidents.index');
        Route::get('/maintenance/service-requests', [MaintenanceController::class, 'serviceRequests'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.service-requests.index');
        Route::post('/maintenance/service-requests', [MaintenanceController::class, 'storeServiceRequest'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceDispatch->value)
            ->name('api.v1.maintenance.service-requests.store');
        Route::get('/maintenance/dashboard', [MaintenanceController::class, 'dashboard'])
            ->middleware(RequireSanctumPermission::class.':'.PermissionKey::MaintenanceView->value)
            ->name('api.v1.maintenance.dashboard');
    });
});

Route::prefix('v1')->middleware([
    AuthenticateApiToken::class,
    EstablishTenantContext::class,
])->group(function (): void {
    Route::get('/identity/context', IdentityContextController::class)
        ->middleware(RequirePermission::class.':'.PermissionKey::IdentityContextView->value)
        ->name('api.v1.identity.context');
});
