<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The instalment providers' return leg — GET /checkout/pending (Lane PLC)
|------------------------------------------------------------------------------
|
| Two routes: GET /checkout/pending, which is where Tabby and Tamara send a
| shopper whose payment was declined or abandoned, and POST
| /checkout/restore-basket, which is the button on the page that lands them.
|
| MOUNTED at the storefront level of routes/web.php, in place of the one-line
| closure that used to answer this URI by throwing the order number away and
| bouncing to an unchanged checkout. The closure is gone rather than left
| underneath: this file declares the same URI and the same route NAME, and
| Laravel keys both its URI map and its name list on the LAST registration, so
| leaving the old line would have worked and read as though two things answered
| one address. CheckoutPlacingOverlayTest pins the require at exactly 1.
|
| WHY IT MUST NOT GO IN A GROUP. The closure it replaces sits at the storefront
| level, in no group at all, alongside `checkout`, `checkout.place` and
| `checkout.success`. This route reads the shopper's session (`kbb_last_order`)
| and their cart cookie, so it needs the `web` stack that the storefront level
| already applies and nothing else — no `auth`, because the shopper placing the
| order is usually a guest, and no `admin-api`, obviously.
|
| A `clear_caches_*` migration ships with this package
| (2027_05_11_000000_clear_caches_placing_overlay). Without it the compiled
| route cache on the live host still holds the old closure, the new controller
| is never reached, and the symptom is that absolutely nothing changes — which
| is the silent half of this whole class of defect and why CLAUDE.md makes the
| migration a convention rather than a judgement call.
|
| WHAT IT ANSWERS. App\Services\Payments\Gateways\RemoteGateway::returnUrl()
| points the `cancel` and `failure` legs of BOTH Tabby and Tamara here, with
| ?order=<number>. See App\Http\Controllers\Store\CheckoutReturnController for
| what it does with that — in short: nothing is written, a paid order is
| forwarded to its receipt rather than told it failed, and everyone else gets a
| page they can use with the real reason on it.
|
*/

use App\Http\Controllers\Store\CheckoutReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/checkout/pending', [CheckoutReturnController::class, 'pending'])->name('checkout.pending');

/*
 * "Put my basket back", pressed by the shopper on the basket page after a
 * payment that did not complete. A POST because it is a write with consequences
 * — the order moves to `failed` and OrderStatus hands the stock and the coupon
 * back with it — and the reasoning for a button rather than a script that fires
 * on arrival is written out in full on CheckoutReturnController::restore().
 *
 * THROTTLED, although the session gate already makes it effectively single-use:
 * `kbb_restorable` is forgotten the moment it is used or refused, so a second
 * press finds nothing. The limit is for the shape of the endpoint rather than
 * for any path through it — an unauthenticated POST that moves an order's
 * status should not be free to hammer, whatever today's guards happen to be.
 * Well above any real shopper, who presses it once.
 */
Route::post('/checkout/restore-basket', [CheckoutReturnController::class, 'restore'])
    ->middleware('throttle:20,1')
    ->name('checkout.restore-basket');
