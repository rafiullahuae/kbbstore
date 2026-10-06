<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PushAdminController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Push Notifications  (Lane PN)
|------------------------------------------------------------------------------
|
| REQUIRED FROM routes/web.php BY THE INTEGRATOR (tools/pn-wire.php writes the
| line), inside the guarded `admin-api` group — `web`, `auth:admin`,
| NoStoreAdminApi, EnforceAdminCapability — below cart-tracking-admin.php:
|
|     require __DIR__.'/push-admin.php';
|
| `send` and `schedule` make the server push to shoppers' phones: mounted
| anywhere unauthenticated they would be a notification cannon with the shop's
| name on it. Nothing here is on /api/*.
|
| Paths (all under /admin-api) and their capability (AdminCapabilities::RULES):
|
|   push.view   GET  push, push/campaigns, push/campaigns/{id}, push/links,
|                    push/analytics
|               POST push/count            (read-only: the live audience count)
|   push.send   POST push/campaigns, PUT/DELETE push/campaigns/{id},
|               POST push/campaigns/{id}/send|schedule|cancel|step,
|               POST push/test, push/settings, push/templates
|
| Shipped with 2027_08_29_100200_clear_caches_push_notifications.php: a route
| added to a cached route table does not exist until the cache is cleared.
*/

Route::prefix('push')->group(function () {
    Route::get('/', [PushAdminController::class, 'overview']);
    Route::get('/campaigns', [PushAdminController::class, 'campaigns']);
    Route::get('/campaigns/{id}', [PushAdminController::class, 'campaign'])->whereNumber('id');
    Route::get('/links', [PushAdminController::class, 'links'])->middleware('throttle:60,1,push-links');
    Route::get('/analytics', [PushAdminController::class, 'analytics']);
    Route::post('/count', [PushAdminController::class, 'count'])->middleware('throttle:60,1,push-count');

    Route::post('/campaigns', [PushAdminController::class, 'save']);
    Route::put('/campaigns/{id}', [PushAdminController::class, 'save'])->whereNumber('id');
    Route::delete('/campaigns/{id}', [PushAdminController::class, 'destroy'])->whereNumber('id');
    Route::post('/campaigns/{id}/send', [PushAdminController::class, 'send'])->whereNumber('id')->middleware('throttle:10,1,push-send');
    Route::post('/campaigns/{id}/schedule', [PushAdminController::class, 'schedule'])->whereNumber('id')->middleware('throttle:10,1,push-send');
    Route::post('/campaigns/{id}/cancel', [PushAdminController::class, 'cancel'])->whereNumber('id');
    Route::post('/campaigns/{id}/step', [PushAdminController::class, 'step'])->whereNumber('id')->middleware('throttle:10,1,push-send');
    Route::post('/test', [PushAdminController::class, 'test'])->middleware('throttle:6,1,push-test');
    Route::post('/settings', [PushAdminController::class, 'saveSettings']);
    Route::post('/templates', [PushAdminController::class, 'saveTemplates']);
});
