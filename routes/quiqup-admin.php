<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Quiqup, and Schedule with Quiqup on Store -> Orders (Lane QQB)
|------------------------------------------------------------------------------
|
| NOT WIRED by the lane. The integrator adds ONE line inside the EXISTING
| admin-api group in routes/web.php (auth:admin + NoStoreAdminApi), beside
| orders-admin.php:
|
|     require __DIR__.'/quiqup-admin.php';
|
| THAT GROUP AND NOTHING ELSE: these routes read customer phones and
| addresses and book a billed courier. /api/* is unauthenticated.
|
| Flat paths, like orders-admin.php, so none can collide with /orders/{id}
| whatever order the requires end up in. Every path is mapped in
| AdminCapabilities::RULES (quiqup.view / quiqup.ship / quiqup.settings) and
| fails closed. A clear_caches_quiqup_* migration ships with each package that
| adds a path here.
*/

use App\Http\Controllers\Admin\QuiqupSettingsController;
use Illuminate\Support\Facades\Route;

// P1 -- Store -> Quiqup
Route::get('/quiqup-settings', [QuiqupSettingsController::class, 'show']);
Route::post('/quiqup-settings', [QuiqupSettingsController::class, 'save']);
Route::post('/quiqup-test', [QuiqupSettingsController::class, 'test']);
Route::get('/quiqup-activity', [QuiqupSettingsController::class, 'activity']);
Route::post('/quiqup-mode', [QuiqupSettingsController::class, 'mode']);
Route::post('/quiqup-test-order', [QuiqupSettingsController::class, 'testOrder']);

// P2 -- Store -> Orders -> Schedule with Quiqup, labels, the Delivery card
use App\Http\Controllers\Admin\QuiqupShipController;

Route::post('/quiqup-preview', [QuiqupShipController::class, 'preview']);
Route::post('/quiqup-schedule', [QuiqupShipController::class, 'schedule']);
Route::post('/quiqup-step', [QuiqupShipController::class, 'step']);
Route::post('/quiqup-cancel', [QuiqupShipController::class, 'cancel']);
Route::post('/quiqup-rebook', [QuiqupShipController::class, 'rebook']);
Route::get('/quiqup-labels', [QuiqupShipController::class, 'labels']);
Route::get('/quiqup-shipment', [QuiqupShipController::class, 'shipment']);
Route::post('/quiqup-refresh', [QuiqupShipController::class, 'refresh']);
