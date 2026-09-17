<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Checkout — the card form's two reports
|--------------------------------------------------------------------------
|
| The card fields are Stripe Elements mounted on /checkout/ itself, so the
| browser is the one that watches the payment happen. These are the two things
| it has to tell the server afterwards.
|
| INTEGRATOR: this file needs one line in routes/web.php, at the TOP LEVEL of
| the file (the `web` middleware group — both endpoints need the session and
| CSRF), immediately after the line that is already there for the checkout
| quantity stepper:
|
|     require __DIR__.'/checkout-line.php';
|
| giving
|
|     require __DIR__.'/checkout-card.php';
|
| Anywhere in that run of top-level requires works; what matters is that it is
| ABOVE `require __DIR__ . '/kbb-brands-blog.php';`, because that file ends in a
| catch-all single-root-segment route.
|
| web.php and NOT api.php, and here that is a security choice rather than a
| convention. Both endpoints act on an order, and the only thing that
| authorises them is `kbb_last_order` in the session — the marker place()
| writes for the browser that actually placed the order. Laravel 11's api.php
| has neither session nor cookie middleware, so on /api/* that check would
| read an empty session and refuse every call; and /api/* is unauthenticated
| and public, which is the last place a route that cancels an order and puts
| stock back on the shelf belongs.
|
| Neither endpoint takes an amount, a status or a card. `/checkout/card/paid`
| reads the payment back from Stripe server-to-server and applies it through
| PaymentConfirmer; `/checkout/card/abandon` cancels the intent at Stripe
| before it releases anything. The order number in the body only selects which
| order is being asked about, and it has to match the session marker or the
| answer is 404 — the same answer a number that does not exist gets, so this
| cannot be used to discover order numbers.
|
| A route added here does nothing until the compiled route cache is cleared, so
| the package carrying it also ships
| database/migrations/2026_09_17_000000_clear_caches_checkout_card_fields.php.
|
*/

/*
 * "The bank approved it." Verified against Stripe before anything is believed,
 * and idempotent with the webhook that says the same thing a moment later.
 *
 * Throttled because it is the one endpoint here that does a synchronous call
 * out to Stripe. The session gate already means a caller can only ever ask
 * about one order — their own — so this is about not letting a stuck tab in a
 * retry loop spend the shop's Stripe rate limit, not about access.
 */
Route::post('/checkout/card/paid', [CheckoutController::class, 'cardConfirmed'])
    ->middleware('throttle:30,1')
    ->name('checkout.cardConfirmed');

/*
 * "I give up on this card, give me my basket back." Cancels the intent at
 * Stripe, fails the order through OrderStatus — which is what returns the
 * stock and releases the coupon use — and puts the cart back to `active`.
 */
Route::post('/checkout/card/abandon', [CheckoutController::class, 'cardAbandoned'])
    ->middleware('throttle:20,1')
    ->name('checkout.cardAbandoned');
