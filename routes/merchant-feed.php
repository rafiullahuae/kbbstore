<?php

/*
|--------------------------------------------------------------------------
| The Google Merchant Center product feed (Lane SEO)
|--------------------------------------------------------------------------
|
| GET /feeds/google-merchant.xml. Public, machine-read, identical for every
| reader -- the same kind of file as /sitemap.xml, so routes/web.php requires
| this INSIDE the `Route::withoutMiddleware(SeoFilesController::STATELESS)`
| group beside sitemap/robots/llms: no session, no Set-Cookie, cacheable.
| See docs/seo-wiring.json block 1.
|
| Not under /feed: that root is reserved for WordPress leftovers
| (PageController::RESERVED_SLUGS). /feeds/ is ours.
|
| Ships with 2027_10_08_100000_clear_caches_seo_feed.php for the compiled route
| table.
|
*/

use App\Http\Controllers\Store\MerchantFeedController;
use Illuminate\Support\Facades\Route;

Route::get('/feeds/google-merchant.xml', MerchantFeedController::class)->name('feeds.google-merchant');
