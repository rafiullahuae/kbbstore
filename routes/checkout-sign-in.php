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
| database/migrations/2027_10_18_100100_clear_caches_checkout_sign_in.php.
|
*/

Route::post('/checkout/sign-in', [CheckoutSignInController::class, 'login'])
    ->middleware('throttle:20,1')
    ->name('checkout.signin');

Route::post('/checkout/sign-in/forgot', [CheckoutSignInController::class, 'forgot'])
    ->middleware('throttle:30,1')
    ->name('checkout.signin.forgot');

/*
 * 2.60.502: a fresh CSRF token for the Sign in window. The owner, on the live
 * shop: "Something went wrong ... this is coming on the sign-in form and also
 * same for forgot email form". A checkout tab opened before the browser signed
 * in or out anywhere (the admin included) holds a token that sign-in retired,
 * and the window's request was refused 419. On a 419 the window asks here
 * once, adopts the token and sends the same request again. Same-origin JSON
 * only (a page on another site cannot read it), never cached, throttled.
 */
Route::get('/checkout/sign-in/token', [CheckoutSignInController::class, 'token'])
    ->middleware('throttle:30,1')
    ->name('checkout.signin.token');
