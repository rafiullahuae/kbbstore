<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Checkout page  (Lane: checkout-page)
|------------------------------------------------------------------------------
|
| Two screens, Desktop and Mobile, behind the owner's brief: "the same options
| as we built for cart page, like squeezing, spacings, paddings, control for
| every section of the checkout page ... please don't disturb any number of
| sections". App\Services\CheckoutPage carries the schema and the argument for
| every default, and nothing in it can add, remove or reorder a section.
|
| Resulting paths:
|
|     GET  /admin-api/checkout-page   every field, grouped into the two tabs
|     POST /admin-api/checkout-page   save
|
| THAT GROUP AND NOTHING ELSE — the one that already carries `web`,
| `auth:admin` and NoStoreAdminApi. /api/* here is unauthenticated, and the
| POST rewrites the page every order is placed from.
|
| CAPABILITY. `checkoutpage.manage`, held by owner, manager and editor: the
| same three that hold `cartpage.manage`, and for the same reason — this is
| storefront appearance. It is its own capability rather than a reuse of
| cartpage.manage so that narrowing one never silently narrows the other from
| another file. Deliberately NOT store.settings: nothing here can take payment
| down or redirect an order, and making an editor an owner to move a slider is
| how capabilities stop being used.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_04_000001_clear_caches_checkout_page.php.
*/

use App\Http\Controllers\Admin\CheckoutPageApiController;
use Illuminate\Support\Facades\Route;

Route::get('/checkout-page', [CheckoutPageApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.checkout-page');

Route::post('/checkout-page', [CheckoutPageApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.checkout-page.save');
