<?php

/*
|--------------------------------------------------------------------------
| Meta and TikTok catalog feeds (Lane MP)
|--------------------------------------------------------------------------
|
| GET /feeds/meta-catalog.xml and GET /feeds/tiktok-catalog.xml. Public and
| machine-read, like /feeds/google-merchant.xml, so routes/web.php requires this
| INSIDE the `Route::withoutMiddleware(SeoFilesController::STATELESS)` group,
| on the line after `require __DIR__.'/merchant-feed.php';` -- no session, no
| Set-Cookie, cacheable. docs/mp-wiring.json, block 1.
|
| Ships with 2027_10_15_130100_clear_caches_marketing_pixels_connect.php.
|
*/

use App\Http\Controllers\Store\CatalogFeedController;
use Illuminate\Support\Facades\Route;

Route::get('/feeds/meta-catalog.xml', CatalogFeedController::class)->name('feeds.meta-catalog');
Route::get('/feeds/tiktok-catalog.xml', CatalogFeedController::class)->name('feeds.tiktok-catalog');
