<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Homepage content → All sections  (Lane HC)
|------------------------------------------------------------------------------
|
| INTEGRATOR: require this file from routes/web.php inside the EXISTING
| admin-api group (web, auth:admin, NoStoreAdminApi), beside the other homepage
| route files:
|
|     require __DIR__.'/homepage-live-admin.php';
|     require __DIR__.'/homepage-hub-admin.php';          <-- this file
|
|     GET  /admin-api/homepage-hub            the section list and editors
|     GET  /admin-api/homepage-hub/products   the manual picker's typeahead
|     POST /admin-api/homepage-hub/preview    what an unsaved source would draw
|
| ALL THREE READ; NONE WRITES. Every save the editor makes goes to the endpoint
| that already owns the value (/admin-api/homepage/content, /admin-api/homepage,
| /admin-api/grid-sections/{id}, and the Banners, Spotted, Video rail and
| Instagram settings endpoints), under THEIR capabilities.
|
| `homepage-hub` and not `homepage/hub`, on purpose: under `homepage/` the
| existing ['*', 'admin-api/homepage/**', 'content.manage'] rule would govern
| them, and the brief is a capability of their own. App\Support\AdminCapabilities
| maps `homepagehub.view` and `homepagehub.search`; a path added here and never
| mapped falls through to owner-only, which is closed.
|
| NOTHING IS CHAINED ONTO THE GROUP — RouteRegistrar::middleware() replaces
| rather than appends — only a throttle per route. The typeahead is debounced
| 300ms on the screen; 120 a minute is a fast typist, not a loop.
|
| ROUTE CACHE: database/migrations/2027_08_04_100100_clear_caches_homepage_hub.php.
*/

use App\Http\Controllers\Admin\HomepageHubController;
use Illuminate\Support\Facades\Route;

Route::get('/homepage-hub/products', [HomepageHubController::class, 'products'])
    ->middleware('throttle:120,1')
    ->name('admin.homepagehub.products');

Route::post('/homepage-hub/preview', [HomepageHubController::class, 'preview'])
    ->middleware('throttle:120,1')
    ->name('admin.homepagehub.preview');

/*
 * Lane FS: a section's Fonts & size tab. The one WRITE on this file, and the
 * one writer of `homepage_section_type`; capability `homepagehub.type`.
 * ROUTE CACHE: 2027_08_10_100100_clear_caches_section_fonts.php.
 */
Route::post('/homepage-hub/type', [\App\Http\Controllers\Admin\HomepageTypeController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.homepagehub.type');

Route::get('/homepage-hub', [HomepageHubController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.homepagehub');
