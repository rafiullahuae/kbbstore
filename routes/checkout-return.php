<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The instalment providers' return leg — GET /checkout/pending (Lane PLC)
|------------------------------------------------------------------------------
|
| ▲ NOT MOUNTED YET. routes/web.php is the integrator's file.
|
| THE EDIT, AND IT IS A REPLACEMENT RATHER THAN AN ADDITION. routes/web.php
| line 185 currently reads, verbatim:
|
|     Route::get('/checkout/pending', fn () => redirect(\App\Support\Url::redirect('/checkout/')))->name('checkout.pending');
|
| Delete that ONE line and put this in its place, in the same spot, immediately
| after the `checkout.success` line:
|
|     require __DIR__.'/checkout-return.php';
|
| Both halves matter, but they are not equally urgent: this file declares the
| same URI and the same route NAME as the closure, and Laravel's RouteCollection
| keys both its URI map and its name list on the last registration — so a
| require placed AFTER the old line already wins, and a half-finished wiring
| behaves correctly rather than ambiguously. Deleting the closure is still the
| right thing to do; leaving it is untidy, not broken. CheckoutPlacingOverlayTest
| pins `substr_count(..., "require __DIR__.'/checkout-return.php';") === 1`,
| which is 0 until the edit is made and 2 if the require is pasted twice.
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
