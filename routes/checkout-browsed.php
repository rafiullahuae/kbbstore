<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Checkout — one-tap add from the Browsed tab
|--------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line in routes/web.php, at the top level of
| the file (the `web` middleware group — session and CSRF, which this endpoint
| needs both of), immediately after
|
|     require __DIR__.'/auth-customer.php';
|
| which is line 576 on this base, giving
|
|     require __DIR__.'/checkout-browsed.php';
|
| Anywhere in that run of top-level requires works; what matters is that it is
| ABOVE `require __DIR__ . '/kbb-brands-blog.php';` (line 588), because that
| file ends in a catch-all single-root-segment route. Route::fallback() higher
| up is not a problem: Laravel matches the fallback last however it was
| registered, which is why the requires already there sit below it.
|
| web.php and NOT api.php, for the same reason the cart endpoints are in
| web.php: the cart is identified by a cookie and protected by CSRF, and
| Laravel 11's api.php has neither session nor cookie middleware. It is also a
| deliberate choice to keep it off /api/*, every path of which is
| unauthenticated and public — this one writes to the shopper's cart.
|
| A route added here does nothing until the compiled route cache is cleared, so
| the package carrying it also ships
| database/migrations/2026_09_15_060000_clear_caches_checkout_browsed_add.php.
|
*/

Route::post('/checkout/browsed-add', [CheckoutController::class, 'browsedAdd'])
    ->name('checkout.browsedAdd');
