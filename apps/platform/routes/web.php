<?php

declare(strict_types=1);

use App\Foundation\Features\Feature;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicArticleController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicRedirectController;
use App\Http\Controllers\PublicStationController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\RequireEnabledFeature;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicPageController::class)->defaults('slug', 'home')->name('home');

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
