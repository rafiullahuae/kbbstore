<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutSignInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Checkout — the "Sign in" window beside "1 Contact" (Lane CO)
|--------------------------------------------------------------------------
|
| INTEGRATOR: one line in routes/web.php, at the TOP LEVEL (the `web` group:
| session, CSRF and the shop firewall, all three of which these need), in the
| run of checkout requires and above `require __DIR__ . '/kbb-brands-blog.php';`
| (that file ends in a catch-all single-segment route):
|
|     require __DIR__.'/checkout-sign-in.php';
|
| NOT routes/api.php: everything under /api/* is unauthenticated and has no
| session, and these sign a shopper in and send mail.
|
| The throttles here are the outer fence only. The real limits are the ones
| the account pages already apply, because both endpoints run their code:
| CustomerAuthController::attempt() (5 tries per address+IP, then a 300-second
| lockout) and PasswordResetController::sendLink() (5 per address+IP and 15 per
| IP, each for 15 minutes).
|
| A route added by a package does not take effect until the compiled route
| cache is cleared, so this ships with
| database/migrations/2027_10_17_100100_clear_caches_checkout_sign_in.php.
|
*/

Route::post('/checkout/sign-in', [CheckoutSignInController::class, 'login'])
    ->middleware('throttle:20,1')
    ->name('checkout.signin');

Route::post('/checkout/sign-in/forgot', [CheckoutSignInController::class, 'forgot'])
    ->middleware('throttle:30,1')
    ->name('checkout.signin.forgot');
