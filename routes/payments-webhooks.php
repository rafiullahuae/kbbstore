<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payment webhooks — Phase 11 (Lane D)
|------------------------------------------------------------------------------
|
| NOT LOADED YET. CLAUDE.md forbids this lane from editing routes/web.php or
| routes/api.php, so the integrator wires it up. One line, inside
| routes/api.php:
|
|     require __DIR__ . '/payments-webhooks.php';
|
| routes/api.php, specifically — NOT web.php. Two reasons:
|
|   1. The api group has no CSRF middleware. The caller is a server at Tabby,
|      Tamara or Stripe with no session and no token; inside the web group
|      every one of these POSTs would be a 419. If it must go in web.php
|      instead, bootstrap/app.php needs:
|          $middleware->validateCsrfTokens(except: ['payments/webhook/*']);
|   2. The api group already carries the SecurityHeaders middleware that
|      routes/api.php applies to everything in it.
|
| Mounted there, the live URLs are (base path included — production serves
| this app under /kbb-upgrade):
|
|     POST https://<host>/kbb-upgrade/api/payments/webhook/tabby/{secret}
|     POST https://<host>/kbb-upgrade/api/payments/webhook/tamara/{secret}
|     POST https://<host>/kbb-upgrade/api/payments/webhook/stripe/{secret}
|
| {secret} is the per-gateway `webhook_secret` from the admin screen. The exact
| URL to paste into each provider's dashboard is shown there, so nobody has to
| assemble it by hand.
|
| Being unauthenticated is the design, not an oversight. The signature is the
| authentication: every gateway verifies the URL secret with hash_equals, and
| then does its own cryptographic check on top — Stripe's HMAC header, Tamara's
| HS256 JWT, and for Tabby an authenticated re-fetch of the payment from their
| API so the POST body decides nothing. A gateway that implements no
| verification cannot be reached here at all: WebhookController 404s anything
| that is not a HandlesWebhooks, which is why `cod` has no endpoint.
|
| The throttle is a blunt instrument against someone hammering the URL with
| guessed secrets. It is set well above any real delivery rate — a provider
| retrying a backlog must never be throttled into a lost payment.
|
*/

use App\Http\Controllers\Payments\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/webhook/{gateway}/{secret}', [WebhookController::class, 'handle'])
    ->where('gateway', '[a-z]+')
    ->where('secret', '[A-Za-z0-9_-]{16,128}')
    ->middleware('throttle:120,1')
    ->name('payments.webhook');
