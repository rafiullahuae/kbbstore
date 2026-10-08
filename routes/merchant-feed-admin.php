<?php

/*
|--------------------------------------------------------------------------
| Growth & Marketing -> Google Shopping feed (Lane SEO)
|--------------------------------------------------------------------------
|
| Required INSIDE the guarded admin-api group in routes/web.php (prefix
| admin-api, ['web', 'auth:admin', NoStoreAdminApi]) -- docs/seo-wiring.json
| block 2. Capability `marketing.feed` for both verbs, from
| AdminCapabilities::ROUTES, failing closed. Nothing is chained onto these
| routes: RouteRegistrar::middleware() replaces rather than appends.
|
*/

use App\Http\Controllers\Admin\MerchantFeedApiController;
use Illuminate\Support\Facades\Route;

Route::get('/merchant-feed', [MerchantFeedApiController::class, 'show'])->name('admin.merchant-feed');
Route::post('/merchant-feed', [MerchantFeedApiController::class, 'save'])->name('admin.merchant-feed.save');
