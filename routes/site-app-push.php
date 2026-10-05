<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The installed shop app's notification question (Lane NT)
|--------------------------------------------------------------------------
|
| App -> Site App -> "Ask shoppers for notifications when the app opens".
| Read by resources/site-app/site-app.js ONLY when the shop runs as an
| installed Home Screen app, so no page pays for these.
|
|   GET  /api/site-app/push       whether to ask, the VAPID key, four strings
|   POST /api/site-app/push       store the browser's push subscription
|   POST /api/site-app/push/off   forget one
|   POST /api/site-app/push/viewed  an out-of-stock product a subscribed phone
|                                 looked at (for "back in stock", later)
|
| MOUNT IN THE ORDINARY WEB GROUP, NOT IN THE STATELESS GROUP THAT HOLDS
| routes/site-app.php: the signed-in shopper comes from the session and the
| POSTs carry CSRF, and the stateless group strips both. Before the Phase 9
| file's catch-all, beside newsletter-public.php. The worker never touches
| /api (SiteApp::BYPASS), so it never answers these from a cache.
*/

use App\Http\Controllers\Store\SiteAppPushController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:20,1,site-app-push')->group(function () {
    Route::get('/api/site-app/push', [SiteAppPushController::class, 'config'])->name('site-app.push');
    Route::post('/api/site-app/push', [SiteAppPushController::class, 'subscribe'])->name('site-app.push.subscribe');
    Route::post('/api/site-app/push/off', [SiteAppPushController::class, 'unsubscribe'])->name('site-app.push.off');
});
Route::post('/api/site-app/push/viewed', [SiteAppPushController::class, 'viewed'])
    ->middleware('throttle:30,1,site-app-push-viewed')
    ->name('site-app.push.viewed');
