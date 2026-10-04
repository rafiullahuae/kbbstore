<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CartTrackingApiController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Cart Tracking  (Lane CT)
|------------------------------------------------------------------------------
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing `admin-api` group — the one that already carries `web`,
| `auth:admin` and NoStoreAdminApi — beside security-admin.php:
|
|     require __DIR__.'/cart-tracking-admin.php';
|
| Every row behind these endpoints carries a shopper's IP address, country and
| user agent, so the guarded group is not a preference: nothing here may ever
| be mounted under /api.
|
| Resulting paths (all under /admin-api):
|
|   GET  cart-tracking                       Carts tab: list, tiles   view
|   GET  cart-tracking/carts/{id}            one cart's history       view
|   GET  cart-tracking/products              Added / Removed tabs     view
|   GET  cart-tracking/blocks                Blocked tab              view
|   GET  cart-tracking/settings              Settings tab             view
|   GET  cart-tracking/export                CSV of the filter / ids  view
|   POST cart-tracking/blocks                block an IP / range      block
|   POST cart-tracking/blocks/{id}/unblock   unblock                  block
|   POST cart-tracking/bulk                  block / delete selected  block
|   POST cart-tracking/settings              save settings            block
|
| CAPABILITIES: `carttracking.view` and `carttracking.block`, both owner and
| manager, mapped in App\Support\AdminCapabilities::RULES; any other role is
| refused by EnforceAdminCapability before the controller runs.
*/

Route::get('/cart-tracking', [CartTrackingApiController::class, 'index']);
Route::get('/cart-tracking/carts/{id}', [CartTrackingApiController::class, 'show'])->whereNumber('id');
Route::get('/cart-tracking/products', [CartTrackingApiController::class, 'products']);
Route::get('/cart-tracking/blocks', [CartTrackingApiController::class, 'blocks']);
Route::get('/cart-tracking/settings', [CartTrackingApiController::class, 'settings']);
Route::get('/cart-tracking/export', [CartTrackingApiController::class, 'export']);
Route::post('/cart-tracking/blocks', [CartTrackingApiController::class, 'block']);
Route::post('/cart-tracking/blocks/{id}/unblock', [CartTrackingApiController::class, 'unblock'])->whereNumber('id');
Route::post('/cart-tracking/bulk', [CartTrackingApiController::class, 'bulk']);
Route::post('/cart-tracking/settings', [CartTrackingApiController::class, 'saveSettings']);
