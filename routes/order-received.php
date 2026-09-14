<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Order-received page — Lane G
|------------------------------------------------------------------------------
|
| NOT LOADED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| the integrator wires it up. One line, at the top level of routes/web.php,
| beside the existing `require __DIR__ . '/kbb-brands-blog.php';` near the end
| of the file:
|
|     require __DIR__ . '/order-received.php';
|
| It must be at the top level (the `web` middleware group), NOT inside the
| admin-api group: this is a storefront form posted by a shopper, and it needs
| the session and the CSRF token that group does not carry.
|
| A route added to a package does not take effect until the compiled route
| cache is cleared, so the package that ships this also ships a
| `clear_caches_*` migration — see CLAUDE.md.
|
| WHAT IT IS. The order-received page offers a guest who checked out without an
| account the chance to finish one. The Customer row already exists (checkout's
| firstOrCreate made it) with no password; this sets one. The rule about which
| rows may be given a password lives in CheckoutController::canSetInitialPassword()
| and is shared with place(), and the endpoint answers identically whether it
| wrote anything or not — see the method for why.
|
| The endpoint is NOT a public write: it refuses any order the session is not
| already allowed to see, on exactly the same rule as the page itself.
|
*/

use App\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::post('/checkout/claim-account', [CheckoutController::class, 'claimAccount'])
    ->name('checkout.claim-account');
