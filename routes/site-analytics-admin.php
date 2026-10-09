<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Analytics  (Lane AN)
|------------------------------------------------------------------------------
|
| Required from routes/web.php INSIDE the guarded admin-api group (`web`,
| `auth:admin`, NoStoreAdminApi), beside the Inquiries line:
|
|     require __DIR__.'/site-analytics-admin.php';   // Analytics (Lane AN)
|
| Aggregates only. AdminCapabilities maps every path (analytics.view for the
| reads, analytics.manage for the settings write). A clear_caches migration
| (2027_10_15_160200) ships with this file.
*/

use App\Http\Controllers\Admin\SiteAnalyticsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-analytics', [SiteAnalyticsApiController::class, 'summary'])
    ->middleware('throttle:60,1')->name('admin.site-analytics');
Route::get('/site-analytics/live', [SiteAnalyticsApiController::class, 'live'])
    ->middleware('throttle:120,1')->name('admin.site-analytics.live');
Route::get('/site-analytics/settings', [SiteAnalyticsApiController::class, 'settings'])
    ->name('admin.site-analytics.settings');
Route::post('/site-analytics/settings', [SiteAnalyticsApiController::class, 'saveSettings'])
    ->middleware('throttle:30,1')->name('admin.site-analytics.settings.save');

// The board's layout, per admin (Lane AN2): drag-and-drop order and hidden blocks.
Route::get('/site-analytics/layout', [SiteAnalyticsApiController::class, 'layout'])
    ->name('admin.site-analytics.layout');
Route::put('/site-analytics/layout', [SiteAnalyticsApiController::class, 'saveLayout'])
    ->middleware('throttle:60,1')->name('admin.site-analytics.layout.save');
Route::delete('/site-analytics/layout', [SiteAnalyticsApiController::class, 'resetLayout'])
    ->middleware('throttle:30,1')->name('admin.site-analytics.layout.reset');
