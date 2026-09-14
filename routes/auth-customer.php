<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Customer password reset + email verification — Lane L
|------------------------------------------------------------------------------
|
| NOT LOADED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| the integrator wires it up with one line at the TOP LEVEL of routes/web.php —
| the `web` middleware group — beside the existing
|
|     require __DIR__.'/order-received.php';
|
| near the end of the file:
|
|     require __DIR__ . '/auth-customer.php';
|
| PLACEMENT, and it matters twice:
|
|   * It must be inside the `web` group, NOT the admin-api group. These are
|     storefront forms posted by shoppers; they need the session and the CSRF
|     token, and two of them need the `customer` guard.
|
|   * It must come BEFORE `require __DIR__ . '/kbb-brands-blog.php';`. That file
|     ends in `/{slug}/`, a single-segment catch-all at the site root. None of
|     the paths below are single-segment, so nothing here is actually shadowed
|     by it today — but the ordering rule is the one that file's own header
|     states, and the next route added here might well be one segment long.
|
| A route added by a package does not take effect until the compiled route cache
| is cleared, so this ships alongside
| database/migrations/2026_09_16_010000_clear_caches_customer_auth.php. See
| CLAUDE.md.
|
|------------------------------------------------------------------------------
| ALREADY IN web.php — DO NOT ADD AGAIN
|------------------------------------------------------------------------------
|
|   GET /my-account/forgot   ->  Store\AccountController::forgot   (name: account.forgot)
|
| That route exists and renders resources/views/store/account/forgot.blade.php.
| The view has posted to /my-account/forgot since it was written and the POST
| route did not exist, so the form 404'd; the POST below is what completes it.
| The GET is deliberately not redeclared here — the first matching registration
| wins, and two declarations of one path is how a later reader ends up editing
| the one that never runs.
|
|------------------------------------------------------------------------------
| SWITCHING IT ON
|------------------------------------------------------------------------------
|
| These endpoints send mail, so they need Store -> Mail filled in (host,
| username, password, from address) or set to the `log` transport. Until then
| MailConfigurator falls back to `log`: the flows all work, the messages are
| written to storage/logs and nothing is delivered. Nothing here 500s because
| mail is unconfigured, by design.
|
*/

use App\Http\Controllers\Store\EmailVerificationController;
use App\Http\Controllers\Store\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Password reset — public. Guarded, not open.
|------------------------------------------------------------------------------
|
| `throttle` here is the outer fence and is deliberately loose. The real limits
| are in the controller, keyed on address+IP and on IP, because the framework's
| route throttle is keyed on the IP alone and cannot see that one client is
| walking a list of addresses. Both layers exist; neither is the only one.
*/
Route::post('/my-account/forgot', [PasswordResetController::class, 'send'])
    ->middleware('throttle:30,1')
    ->name('customer.password.email');

// The token is a 64-character hex string from DatabaseTokenRepository, and the
// id is an integer. Constrained so a malformed link is a 404 at the router
// rather than a string the controller has to defend against.
Route::get('/my-account/reset/{id}/{token}/', [PasswordResetController::class, 'edit'])
    ->where('id', '[0-9]+')
    ->where('token', '[A-Za-z0-9]+')
    ->name('customer.password.reset');

Route::post('/my-account/reset', [PasswordResetController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('customer.password.update');

/*
|------------------------------------------------------------------------------
| Email verification
|------------------------------------------------------------------------------
|
| The confirm link is PUBLIC and must stay public: it is clicked from a mail
| client, frequently on a different device from the one that signed up, and
| putting it behind `auth:customer` would bounce every customer to a login form
| they cannot pass without the account they are trying to confirm. Its
| authority is the MAC in the query string, not the session.
|
| The digest segment is 32 hex characters (Customer::verificationHash()).
*/
Route::get('/my-account/verify/{id}/{hash}/', [EmailVerificationController::class, 'verify'])
    ->where('id', '[0-9]+')
    ->where('hash', '[a-f0-9]{32}')
    ->middleware('throttle:60,1')
    ->name('customer.verify');

/*
| Sending, by contrast, is for the signed-in customer only — there is then no
| address to accept from the caller and so no enumeration surface at all.
|
| ONE THING FOR THE INTEGRATOR TO KNOW, and it is not this lane's to fix.
| bootstrap/app.php sets `redirectGuestsTo(fn () => route('admin.login'))`
| globally, so an unauthenticated request to anything behind `auth:customer`
| lands on the BACK-OFFICE login, not the storefront one. That is already true
| of /my-account/orders and every other guarded account page in web.php, so the
| two routes below are consistent with what is there rather than a new problem —
| but it is a storefront customer being shown an admin screen, and the fix
| (a guard-aware redirect callback in bootstrap/app.php) belongs to whoever owns
| that file.
*/
Route::middleware('auth:customer')->group(function () {
    Route::get('/my-account/verify', [EmailVerificationController::class, 'notice'])
        ->name('customer.verify.notice');

    Route::post('/my-account/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:10,1')
        ->name('customer.verify.resend');
});
