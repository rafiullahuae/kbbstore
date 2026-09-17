<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Back-in-stock alerts and basket reminders — public (Lane EN)
|------------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line in routes/web.php, at the TOP LEVEL of
| the file — the ordinary `web` middleware group, beside auth-customer.php and
| newsletter-public.php, which these routes are a sibling of. Immediately after
|
|     require __DIR__.'/newsletter-public.php';
|
| giving
|
|     require __DIR__.'/outbound-public.php';
|
| Anywhere in that run of top-level requires works; what matters is that it is
| ABOVE `require __DIR__ . '/kbb-brands-blog.php';`, because that file ends in a
| catch-all single-root-segment route. Route::fallback() higher up is not a
| problem: Laravel matches the fallback last however it was registered.
|
| WHERE IT MUST NOT GO, and this is the same list newsletter-public.php gives
| because these routes have the same shape:
|
|   - NOT in the admin-api group. Two of these are opened by a shopper from
|     their inbox. Behind `auth:admin` every one of them 302s to a login page
|     and the feature is dead.
|
|   - NOT in routes/api.php. Everything there is unauthenticated BY DESIGN and
|     is, per CLAUDE.md, the group that has leaked three times. These routes
|     must be unauthenticated, but they must also carry the `web` group's
|     session and CSRF middleware: /cart/remind-me reads the CART COOKIE to
|     decide whose basket it is talking about, and /mail-preferences is the
|     acting half of a GET-then-POST pair where @csrf is what stops a
|     third-party page from unsubscribing this shop's customers with a hidden
|     form.
|
| Resulting paths:
|
|     POST /notify-me                                back-in-stock request
|     POST /cart/remind-me                           basket reminder opt-in
|     GET  /mail-preferences/{kind}/{id}/            ?expires=&signature=
|     POST /mail-preferences
|
| WHY GET AND POST ARE SPLIT on the last two: mail scanners, link-safety
| rewriters and inbox previewers fetch every URL in a message before a human
| sees it. A GET that acted would let Outlook's Safe Links unsubscribe a
| recipient who never pressed anything. The GET renders a page and changes
| nothing; the POST does the work. Store\MailPreferencesController's header has
| the long version.
|
| A `clear_caches_*` migration ships with this package, per CLAUDE.md: a route
| added here does nothing on the server until the compiled route cache is
| dropped. See
| database/migrations/2026_11_10_000003_clear_caches_stock_alerts_and_recovery.php.
*/

use App\Http\Controllers\Store\CartRecoveryController;
use App\Http\Controllers\Store\MailPreferencesController;
use App\Http\Controllers\Store\StockAlertController;
use Illuminate\Support\Facades\Route;

/*
 * THE TWO CAPTURE FORMS ARE THROTTLED HARDER THAN THE OPT-OUT, and the
 * asymmetry is the point.
 *
 * Both of these are public, unauthenticated, take an address a stranger chose
 * and cause this shop to write it down. Without a ceiling they are a way to
 * fill this shop's tables with other people's addresses — and, through the
 * back-in-stock sweep, eventually to mail them. The unique indexes mean a
 * repeat for the same shelf is free, so this ceiling only ever binds on
 * somebody enumerating.
 *
 * Six a minute per IP is generous for a human filling in a form and hopeless
 * for a script.
 */
Route::middleware('throttle:6,1')->group(function () {
    Route::post('/notify-me', [StockAlertController::class, 'store'])
        ->name('stock.notify');

    Route::post('/cart/remind-me', [CartRecoveryController::class, 'store'])
        ->name('cart.remindMe');
});

/*
 * The opt-out, throttled far more generously.
 *
 * The signature is the real control here — a forged one gets the same page as
 * an expired one and neither changes anything — and the throttle is only a
 * ceiling on what somebody walking row ids can spend of this shop's database on
 * a shared host. It is deliberately loose because a recipient who clicks an
 * unsubscribe link twice, or whose mail client prefetches it and then opens it,
 * must NEVER be met with a 429 on the page that was supposed to get them off
 * the list. An unsubscribe that rate-limits is an unsubscribe that does not
 * work, and that is how a shop collects spam complaints instead of opt-outs.
 */
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/mail-preferences/{kind}/{id}/', [MailPreferencesController::class, 'form'])
        ->whereIn('kind', ['stock', 'cart'])
        ->whereNumber('id')
        ->name('mail.preferences.form');

    Route::post('/mail-preferences', [MailPreferencesController::class, 'act'])
        ->name('mail.preferences');
});
