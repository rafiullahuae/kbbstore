<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The instalment providers' return leg — GET /checkout/pending (Lane PLC)
|------------------------------------------------------------------------------
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
