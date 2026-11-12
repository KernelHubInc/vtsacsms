<?php

declare(strict_types=1);

use App\Foundation\Features\Feature;
use App\Http\Controllers\AcceptOperatorInvitationController;
use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicArticleController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicRedirectController;
use App\Http\Controllers\PublicStationController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StagingKycPolicyController;
use App\Http\Controllers\WorkforceEnrollmentController;
use App\Http\Middleware\RequireEnabledFeature;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicPageController::class)->defaults('slug', 'home')->name('home');

Route::get('/workforce/setup', [WorkforceEnrollmentController::class, 'show'])->name('workforce-enrollment.show');
Route::post('/workforce/setup', [WorkforceEnrollmentController::class, 'complete'])->middleware('throttle:auth.recovery')->name('workforce-enrollment.complete');

Route::get('/accept-invitation', [AcceptOperatorInvitationController::class, 'show'])->name('operator-invitation.show');
Route::post('/accept-invitation', [AcceptOperatorInvitationController::class, 'accept'])->middleware('throttle:auth.recovery')->name('operator-invitation.accept');

Route::get('/reset-password/{token}', [PasswordController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{32,128}')->name('password.reset');
Route::post('/reset-password', [PasswordController::class, 'reset'])
    ->middleware('throttle:auth.recovery')->name('password.update');

Route::get('/account-deletion', [AccountDeletionController::class, 'show'])->name('public.account-deletion');
Route::post('/account-deletion/code', [AccountDeletionController::class, 'send'])
    ->middleware('throttle:account-deletion.code')->block(10, 3)->name('public.account-deletion.send');
Route::post('/account-deletion/confirm', [AccountDeletionController::class, 'confirm'])
    ->middleware('throttle:account-deletion.confirm')->block(15, 3)->name('public.account-deletion.confirm');

Route::get('/staging/kyc/{page}', StagingKycPolicyController::class)
    ->name('kyc.staging-policy');

$publicPages = [
    'network' => 'ev-charging-network',
    'charging-map' => 'charging-map',
    'drivers' => 'for-ev-drivers',
    'operators-and-site-hosts' => 'for-operators-and-site-hosts',
    'installation-and-maintenance' => 'installation-and-maintenance',
    'mobile-app' => 'mobile-app',
    'news-and-resources' => 'news-and-resources',
    'partner-program' => 'partner-program',
    'frequently-asked-questions' => 'frequently-asked-questions',
    'contact' => 'contact',
    'support' => 'support',
    'privacy-policy' => 'privacy-policy',
    'terms-of-service' => 'terms-of-service',
    'charging-terms' => 'charging-terms',
    'refund-policy' => 'refund-policy',
];

foreach ($publicPages as $path => $slug) {
    Route::get('/'.$path, PublicPageController::class)->defaults('slug', $slug)
        ->name('public.'.$slug);
}

Route::get('/resources/{slug}', PublicArticleController::class)->name('public.articles.show');
Route::get('/stations/{slug}', PublicStationController::class)->name('public.stations.show');
Route::get('/sitemap.xml', SitemapController::class)->name('public.sitemap');
Route::get('/robots.txt', RobotsController::class)->name('public.robots');

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/api/health', [HealthController::class, 'ready'])->name('api.health');
$milestoneTwoFeatures = [
    'ocpp' => Feature::Ocpp,
    'remote-charging' => Feature::RemoteCharging,
    'real-payments' => Feature::RealPayments,
    'settlements' => Feature::Settlements,
    'ocpi' => Feature::Ocpi,
];
foreach ($milestoneTwoFeatures as $path => $feature) {
    Route::view('/milestone-2/'.$path, 'milestone-two', ['feature' => $feature])
        ->middleware(RequireEnabledFeature::class.':'.$feature->value)
        ->name('milestone-two.'.$path);
}

if (app()->environment(['local', 'testing'])) {
    Route::view('/design-system', 'design-system')->name('design-system');
}

Route::fallback(PublicRedirectController::class);
