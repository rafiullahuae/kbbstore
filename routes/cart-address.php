<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The cart page's delivery-address row  (Lane: cart-page)
|------------------------------------------------------------------------------
|
| The three calls behind the docked "Please choose your delivery address" row
| and the sheet it opens. App\Http\Controllers\Store\CartAddressController
| carries the reasoning; the parts that belong to the ROUTE are here.
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, at the TOP LEVEL of the
| `web` group — the same place routes/auth-customer.php is required — and
| BEFORE `require __DIR__ . '/kbb-brands-blog.php';`, whose last route is a
| single-segment catch-all at the site root:
|
|     require __DIR__ . '/cart-address.php';
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. Nothing below is single-segment, so
| nothing here is actually shadowed today — but the ordering rule is the one
| that file's own header states.
|
| Resulting paths:
|
|     GET  /cart/address              the saved list, the current choice and
|                                     the geo defaults, in one payload
|     POST /cart/address              add an address and choose it
|     POST /cart/address/{id}/choose  choose a saved one
|
| NOT routes/api.php, AND THIS IS THE WHOLE POINT. /api/* in this application is
| unauthenticated — CLAUDE.md carries a landmine for each time that was
| forgotten, and every one of them leaked a column somebody could read. These
| three read and write a named person's home address. They need the session
| (the guest's choice lives there and nowhere else) and they need CSRF, so they
| belong in the `web` group and only there.
|
| THE GUARD SPLIT, which is the part worth reading twice:
|
|   * GET /cart/address and POST /cart/address are OPEN, because a signed-out
|     shopper has to be able to type an address into the sheet and see it land
|     in the docked row. Neither touches the database for a guest: the payload
|     is a session read and the write is a session write. No customer row is
|     manufactured for somebody who has not signed up.
|
|   * POST /cart/address/{id}/choose is behind `auth:customer`, because an id
|     only means anything to a shopper who has an address book. The controller
|     ALSO resolves every id through $customer->addresses(), so the middleware
|     is a second lock on the same door rather than the only one — and an id
|     belonging to somebody else comes back 404, never 403. A 403 is a
|     confirmation that the row exists; see the note on
|     Api\QuizController::expertRequest in CLAUDE.md for the same reasoning
|     about the same class of oracle.
|
| whereNumber on {id}, so a non-numeric id never reaches a findOrFail and never
| becomes a database round trip.
|
| THROTTLES, and they are on the route rather than in the controller. The POSTs
| write a row per call for a signed-in shopper; without a ceiling a held key is
| an address book with ten thousand entries in it and a sheet that takes a
| second to open. The numbers are far above any honest use — nobody adds thirty
| addresses in a minute — and far below a rate that costs anything.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_11_28_000001_clear_caches_cart_page.php. See
| CLAUDE.md.
*/

use App\Http\Controllers\Store\CartAddressController;
use Illuminate\Support\Facades\Route;

Route::get('/cart/address', [CartAddressController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('cart.address');

Route::post('/cart/address', [CartAddressController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('cart.address.store');

Route::post('/cart/address/{id}/choose', [CartAddressController::class, 'choose'])
    ->whereNumber('id')
    ->middleware(['auth:customer', 'throttle:60,1'])
    ->name('cart.address.choose');
