<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Cart page  (Lane: cart-page)
|------------------------------------------------------------------------------
|
| The screen behind the owner's cart-page brief: the squeezed product rows, the
| full-width recommended rail, the minimal coupon box, the summary, the trust
| row and the two docked bars, each with its own slider. App\Services\CartPage
| carries the schema and the argument for every default.
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/cart-page-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line.
|
| Resulting paths:
|
|     GET  /admin-api/cart-page           every field, grouped into tabs, plus
|                                         the rail's chosen products resolved
|                                         to names
|     POST /admin-api/cart-page           save
|     GET  /admin-api/cart-page/products  search the catalogue for the picker
|
| THAT GROUP AND NOTHING ELSE. /api/* here is unauthenticated; the search
| endpoint would hand a stranger the whole catalogue including anything hidden
| from the shop grid, and the POST rewrites what every shopper sees on the page
| they check out from.
|
| CAPABILITY. `cartpage.manage`, held by owner, manager and editor — the same
| three that hold `content.manage`, and for the same reason: this is storefront
| appearance. It is a capability of its own rather than a reuse of
| content.manage because the day somebody narrows THAT to the people who write
| blog posts, this should not narrow with it silently in another file. It is
| deliberately NOT store.settings: nothing here can take the storefront down or
| redirect its mail, and making an editor an owner to move a slider is how
| capabilities stop being used.
|
| The '/**' line is a SIBLING of the exact one above it, not a parent: matching
| is by pattern and 'admin-api/cart-page' does not match
| 'admin-api/cart-page/products', so both are needed. A '*' rule and not a
| GET/POST split, because there is no reading half here that a narrower role
| should reach without the writing half — the search endpoint exists only to
| feed the picker that writes.
|
| THROTTLES on the route. The search runs a four-column LIKE with a whereHas
| per keystroke; the screen debounces, but a debounce is a promise made by the
| browser and the ceiling is the one made by the server.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_11_28_000001_clear_caches_cart_page.php.
*/

use App\Http\Controllers\Admin\CartPageApiController;
use Illuminate\Support\Facades\Route;

Route::get('/cart-page', [CartPageApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.cart-page');

Route::post('/cart-page', [CartPageApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.cart-page.save');

Route::get('/cart-page/products', [CartPageApiController::class, 'products'])
    ->middleware('throttle:120,1')
    ->name('admin.cart-page.products');
