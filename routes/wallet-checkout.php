<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Checkout — what the wallet sheet is allowed to say (Lane WAL)
|------------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line in routes/web.php, at the TOP LEVEL of
| the file (the `web` middleware group — it needs the session and CSRF),
| immediately after the line that is already there for the card form's two
| reports:
|
|     require __DIR__.'/checkout-card.php';
|
| giving
|
|     require __DIR__.'/wallet-checkout.php';
|
| Anywhere in that run of top-level requires works; what matters is that it is
| ABOVE `require __DIR__ . '/kbb-brands-blog.php';`, which ends in a catch-all
| single-root-segment route.
|
| web.php and NOT api.php, and here that is the same security choice
| checkout-card.php records: the endpoint reads THIS visitor's basket, which is
| identified by CartService's cookie and by nothing else. Laravel 11's api.php
| has neither session nor cookie middleware, so on /api/* it would read an empty
| basket and answer 422 for everybody — and /api/* is unauthenticated and
| public, which is the last place a per-visitor figure belongs.
|
| WHAT IT IS FOR. Apple Pay and Google Pay put a total on a sheet before the
| shopper authorises anything, and that total may not be a number the browser
| worked out. This answers with the figure place() would write onto the order:
| the basket's total for the selected country and emirate, plus the gift fee
| from settings, plus the gateway's own surcharge. Integer fils, no float on the
| path. See CheckoutController::walletAmount().
|
| A route added here does nothing until the compiled route cache is cleared, so
| the package carrying it also ships
| database/migrations/2027_05_06_000000_clear_caches_wallet_domain.php.
|
*/

/*
 * Throttled, and the number is chosen rather than copied. The express row asks
 * for this when it first appears and again, debounced, whenever a field that
 * can move the total is left — country, emirate, the gift tick. A shopper
 * correcting an address touches a handful of fields; 60 a minute is far above
 * any real rate of that and far below a useful rate for anything else.
 *
 * The session gate underneath means a caller can only ever ask about their own
 * basket, so this is about not letting a stuck tab in a retry loop spend the
 * shop's shipping-rate lookups, not about access.
 */
Route::post('/checkout/wallet/amount', [CheckoutController::class, 'walletAmount'])
    ->middleware('throttle:60,1')
    ->name('checkout.walletAmount');
