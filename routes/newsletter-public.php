<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Newsletter double opt-in — public (Lane EE)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file at the top level of the ordinary
| web group, beside auth-customer.php, which these routes are a sibling of. The
| notes below are why it belongs there and nowhere else — they are the reasoning
| behind the mount, not a request for one.
|
| WHERE IT MUST NOT GO, and this matters more than it looks:
|
|   - NOT in the admin-api group. These are opened by a shopper from their
|     inbox. Behind `auth:admin` every one of them 302s to a login page and the
|     whole feature is dead.
|
|   - NOT in routes/api.php. Everything there is unauthenticated BY DESIGN and
|     is, per CLAUDE.md, the group that has leaked three times. These routes
|     must be unauthenticated, but they must also carry the `web` group's
|     session and CSRF middleware, because the two POSTs below are the acting
|     half of a GET-then-POST pair and @csrf is what stops a third-party page
|     from unsubscribing this shop's customers with a hidden form.
|
| These routes take a SIGNED link and act on it, so they are guarded by the
| signature rather than by a session — the same trade the email-verification
| route at routes/web.php:109 makes, and the reason both are top-level rather
| than inside `auth:customer`. A subscriber usually has no account at all.
|
| Resulting paths:
|
|     GET  /newsletter/confirm/{id}/          ?expires=&signature=
|     POST /newsletter/confirm
|     GET  /newsletter/unsubscribe/{id}/      ?expires=&signature=
|     POST /newsletter/unsubscribe
|
| WHY GET AND POST ARE SPLIT. Mail scanners, link-safety rewriters and inbox
| previewers fetch every URL in a message before a human sees it. A GET that
| acted would let Outlook's Safe Links subscribe or unsubscribe a recipient who
| never pressed anything — and in the confirm direction that is double opt-in
| with the opt-in removed. The GETs render a page and change nothing; the POSTs
| do the work. App\Http\Controllers\Store\NewsletterController's header has the
| long version.
|
| THROTTLED, all four. The signature is the real control — a forged one gets the
| same page as an expired one and neither changes anything — but the throttle is
| the ceiling on what an attacker walking subscriber ids can spend of this
| shop's database on a shared host. It is deliberately generous: a shopper who
| clicks a link twice, or whose mail client prefetches it, must never be met with
| a 429 on a page they were sent to.
|
| A `clear_caches_*` migration ships with this package, per CLAUDE.md: a route
| added here does nothing on the server until the compiled route cache is
| dropped. See database/migrations/2026_11_09_000002_clear_caches_outbound_mail.php.
*/

use App\Http\Controllers\Store\NewsletterController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/newsletter/confirm/{id}/', [NewsletterController::class, 'confirmForm'])
        ->whereNumber('id')
        ->name('newsletter.confirm.form');

    Route::post('/newsletter/confirm', [NewsletterController::class, 'confirm'])
        ->name('newsletter.confirm');

    Route::get('/newsletter/unsubscribe/{id}/', [NewsletterController::class, 'unsubscribeForm'])
        ->whereNumber('id')
        ->name('newsletter.unsubscribe.form');

    Route::post('/newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])
        ->name('newsletter.unsubscribe');
});
