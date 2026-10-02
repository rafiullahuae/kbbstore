<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The storefront admin layer — the thin bar and the quick-edit pencil (Lane RA)
|------------------------------------------------------------------------------
|
| INTEGRATOR — ONE LINE, and it must go INSIDE the existing admin-api group in
| routes/web.php: the group opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside `Route::middleware('auth:admin')`. Beside the
| categories-brands-admin require is the natural place:
|
|     // The storefront's admin bar and quick-edit pencil (Lane RA). Same
|     // guarded group: these answer only a signed-in admin, no-store.
|     require __DIR__.'/storefront-admin.php';
|
| WHY THE GROUP MATTERS. The context read returns the console's address and an
| order count; the save rewrites what every shopper sees at the top of a
| category or brand page. Mounted outside auth:admin both would be public.
| NoStoreAdminApi gives them `Cache-Control: no-store`, which is the other half
| of the promise: an admin's answer is never kept by anything between him and
| the server. Not in routes/api.php -- "/api/* is unauthenticated".
|
| Resulting paths:
|
|     GET  /admin-api/storefront/context?path=/collections/skincare/
|     POST /admin-api/storefront/quick-edit/{category|brand}/{id}/preview
|     POST /admin-api/storefront/quick-edit/{category|brand}/{id}
|
| Capabilities (App\Support\AdminCapabilities::RULES): context is admin.access
| and decides per capability itself; preview and save are
| storefront.quick_edit. CSRF is the web group's, as for every admin-api write.
|
| The picture is NOT uploaded here. The pop-up posts it to the existing
| /admin-api/media/upload (MediaUploadController) -- one upload path, one set of
| type, size and SVG rules -- and sends the URL it returns as header_image.
|
*/

use App\Http\Controllers\Admin\StorefrontAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/storefront/context', [StorefrontAdminController::class, 'context'])
    ->middleware('throttle:120,1');

Route::post('/storefront/quick-edit/{type}/{id}/preview', [StorefrontAdminController::class, 'preview'])
    ->where('type', 'category|brand')
    ->where('id', '[0-9]+')
    ->middleware('throttle:90,1');

Route::post('/storefront/quick-edit/{type}/{id}', [StorefrontAdminController::class, 'save'])
    ->where('type', 'category|brand')
    ->where('id', '[0-9]+')
    ->middleware('throttle:20,1');
