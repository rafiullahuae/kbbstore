<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Email Marketing — the public half (Lane EK)
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane to edit routes/web.php, so the integrator wires this
| file: ONE line, at the TOP LEVEL of routes/web.php (the ordinary web group,
| NOT inside admin-api and NOT in routes/api.php), directly below the line
| `require __DIR__.'/mail-kit.php';`:
|
|     require __DIR__.'/emails-marketing-public.php';
|
| Opened from an inbox by somebody who is not signed in, so they cannot sit
| behind auth; the token in each URL is the credential (CampaignTracking). Every
| path has three or more segments, so the Phase 9 one-segment catch-all at the
| bottom of web.php cannot reach them wherever the line goes.
|
|     GET  /m/o/{token}               open pixel                 throttle 240/min
|     GET  /m/c/{token}/{link}/{sig}  tracked click (302)        throttle 240/min
|     GET  /m/u/{token}               unsubscribe page           throttle 60/min
|     POST /m/u/{token}               unsubscribe (one-click)    throttle 60/min
|
| THE PIXEL AND THE CLICK ARE STATELESS: no session, no cookie — an email
| client loading a picture must not be handed a session. The unsubscribe POST
| is CSRF-exempt and nothing else: RFC 8058's one-click POST comes from the
| mail provider, which has no token to carry, and the URL's own token is what
| authorises it. The throttles are generous on purpose — an unsubscribe that
| answers 429 is an unsubscribe that does not work.
|
| Shipped with 2027_07_28_300900_clear_caches_email_marketing.php.
*/

use App\Http\Controllers\Store\CampaignPublicController;
use App\Http\Controllers\Store\SeoFilesController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(SeoFilesController::STATELESS)->middleware('throttle:240,1,campaign-track')->group(function () {
    Route::get('/m/o/{token}', [CampaignPublicController::class, 'open'])->name('campaign.open');
    Route::get('/m/c/{token}/{link}/{sig}', [CampaignPublicController::class, 'click'])->name('campaign.click');
});

Route::middleware('throttle:60,1,campaign-unsubscribe')->group(function () {
    Route::get('/m/u/{token}', [CampaignPublicController::class, 'page'])->name('campaign.unsubscribe.page');
    Route::post('/m/u/{token}', [CampaignPublicController::class, 'unsubscribe'])
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('campaign.unsubscribe');
});
