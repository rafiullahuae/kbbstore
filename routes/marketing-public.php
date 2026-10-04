<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Marketing Emails — the public end (Lane MK, docs/EMAILS-PLAN.md §5)
|------------------------------------------------------------------------------
|
| TO BE MOUNTED by the integrator at the TOP LEVEL of routes/web.php, in the
| run of public requires (beside `require __DIR__.'/outbound-public.php';` and
| `require __DIR__.'/mail-kit.php';`), ABOVE
| `require __DIR__ . '/kbb-brands-blog.php';` — that file ends in a catch-all
| single-segment route:
|
|     require __DIR__.'/marketing-public.php';
|
| `email` is in PageController::RESERVED_SLUGS, so an article published at
| /email/ can never shadow these, and RootSlugCollisionTest keeps it so.
|
| NOT in routes/api.php (CLAUDE.md: everything there is unauthenticated by
| design and has leaked three times) and NOT behind auth: every one of these
| is opened from an inbox by someone who is not signed in.
|
|     GET  /email/u/{token}            the unsubscribe page: one button, and
|                                      what still arrives (order emails)
|     POST /email/u/{token}            RFC 8058 one-click unsubscribe. THE ONLY
|                                      CSRF-EXEMPT ROUTE MARKETING ADDS, by exact
|                                      path, at the route (not in bootstrap/,
|                                      which never ships — routes/import-chain.php
|                                      sets out why). A mail provider's one-click
|                                      POST carries no session and no token; the
|                                      credential is the HMAC in the path.
|     GET  /email/c/{token}/{n}        a click: redirects ONLY to row n of that
|                                      campaign's mkt_links; an unknown token or
|                                      n goes to the shop's home page. No open
|                                      redirect is possible: the destination is
|                                      never read from the request.
|     GET  /email/art/{name}.jpg       a picture that ships with the shop (the
|                                      Autumn glow banner), from a closed list.
|
| THE TOKEN IS JUDGED THE SAME WAY EVERYWHERE. A forged, an unknown and a
| valid unsubscribe token all get the same page with the same words, and the
| POST answers 200 the same way for every token (UnsubscribeToken::find()
| does the same work for all three, so the timing says nothing either).
|
| Each route has its OWN throttle bucket (the third throttle argument).
|
| A clear_caches migration ships with this file:
| database/migrations/2027_07_28_501100_clear_caches_marketing_emails.php.
*/

use App\Http\Controllers\Store\MarketingEmailController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/email/u/{token}', [MarketingEmailController::class, 'unsubscribePage'])
    ->middleware('throttle:20,1,mkt-unsub')
    ->name('marketing.unsubscribe');

/*
 * Generous, because a one-click POST comes from the mail PROVIDER's servers:
 * a busy Gmail egress address unsubscribing several of this shop's customers
 * in one minute must never be met with a 429. An unsubscribe that rate-limits
 * is an unsubscribe that does not work.
 */
Route::post('/email/u/{token}', [MarketingEmailController::class, 'unsubscribe'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1,mkt-unsub-post')
    ->name('marketing.unsubscribe.post');

Route::get('/email/c/{token}/{n}', [MarketingEmailController::class, 'click'])
    ->middleware('throttle:60,1,mkt-click')
    ->name('marketing.click');

Route::get('/email/art/{name}.jpg', [MarketingEmailController::class, 'art'])
    ->where('name', '[a-z0-9-]{1,40}')
    ->middleware('throttle:120,1,mkt-art')
    ->name('marketing.art');
