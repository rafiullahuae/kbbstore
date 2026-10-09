<?php

/*
|--------------------------------------------------------------------------
| Growth & Marketing -> Marketing Pixels: Connect, Custom code (Lane MP)
|--------------------------------------------------------------------------
|
| Required INSIDE the guarded admin-api group in routes/web.php (prefix
| admin-api, ['web', 'auth:admin', NoStoreAdminApi]), on the line after the two
| existing `/marketing-pixels` routes. docs/mp-wiring.json, block 2.
|
| Capabilities, from AdminCapabilities::RULES, failing closed:
|   /marketing-pixels/custom-code[/**]  marketing.customcode  (owner only)
|   /marketing-pixels/**                marketing.pixels.connect (owner, manager)
|
| The checks call Meta, Google and TikTok, so they are throttled per admin;
| the OAuth callback is a GET because Facebook redirects the browser to it —
| its CSRF defence is the single-use session state, checked before the code.
|
| Ships with 2027_10_15_130100_clear_caches_marketing_pixels_connect.php.
|
*/

use App\Http\Controllers\Admin\CustomCodeApiController;
use App\Http\Controllers\Admin\PixelConnectApiController;
use Illuminate\Support\Facades\Route;

Route::get('/marketing-pixels/connect', [PixelConnectApiController::class, 'show'])->name('admin.pixels.connect');
Route::post('/marketing-pixels/connect', [PixelConnectApiController::class, 'save'])->name('admin.pixels.connect.save');
Route::post('/marketing-pixels/check/{what}', [PixelConnectApiController::class, 'check'])
    ->whereIn('what', ['meta', 'ga4', 'tiktok', 'live', 'feed'])
    ->middleware('throttle:20,1')
    ->name('admin.pixels.check');
Route::get('/marketing-pixels/events', [PixelConnectApiController::class, 'events'])->name('admin.pixels.events');
Route::post('/marketing-pixels/meta/start', [PixelConnectApiController::class, 'metaStart'])->name('admin.pixels.meta.start');
Route::get('/marketing-pixels/meta/callback', [PixelConnectApiController::class, 'metaCallback'])
    ->middleware('throttle:20,1')
    ->name('admin.pixels.meta.callback');
Route::post('/marketing-pixels/meta/pick', [PixelConnectApiController::class, 'metaPick'])->name('admin.pixels.meta.pick');

Route::get('/marketing-pixels/custom-code', [CustomCodeApiController::class, 'show'])->name('admin.pixels.customcode');
Route::post('/marketing-pixels/custom-code', [CustomCodeApiController::class, 'save'])->name('admin.pixels.customcode.save');
Route::post('/marketing-pixels/custom-code/restore/{id}', [CustomCodeApiController::class, 'restore'])
    ->whereNumber('id')
    ->name('admin.pixels.customcode.restore');
