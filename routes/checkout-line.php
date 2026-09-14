<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Checkout — quantity and remove, in place
|--------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line in routes/web.php, at the top level of
| the file (the `web` middleware group — session and CSRF, which this endpoint
| needs both of), immediately after the line that is already there for the
| Browsed one-tap add:
|
|     require __DIR__.'/checkout-browsed.php';
|
| giving
|
|     require __DIR__.'/checkout-line.php';
|
| Anywhere in that run of top-level requires works; what matters is that it is
| ABOVE `require __DIR__ . '/kbb-brands-blog.php';`, because that file ends in a
| catch-all single-root-segment route.
|
| web.php and NOT api.php, for the same reason every other cart endpoint here
| is: the cart is identified by a cookie and protected by CSRF, and Laravel 11's
| api.php has neither session nor cookie middleware. It is also a deliberate
| choice to keep it off /api/*, every path of which is unauthenticated and
| public — this one writes to the shopper's cart.
|
| A route added here does nothing until the compiled route cache is cleared, so
| the package carrying it also ships
| database/migrations/2026_09_18_010000_clear_caches_checkout_line_update.php.
|
*/

Route::post('/checkout/line', [CheckoutController::class, 'lineUpdate'])
    ->name('checkout.lineUpdate');

/*
 * The coupon box on the same page, for the same reason: /api/cart/coupon
 * renders the mini-cart and the cart page, neither of which is on screen at
 * checkout, so applying a code could only be shown by reloading — which threw
 * away every field the shopper had already filled in. Same group, same
 * middleware: a shopper posts it, so it needs the session and CSRF.
 */
Route::post('/checkout/coupon', [CheckoutController::class, 'couponUpdate'])
    ->name('checkout.couponUpdate');
