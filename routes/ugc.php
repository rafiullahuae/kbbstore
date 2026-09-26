<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Shoppable video — the PUBLIC endpoints  (Lane V3 — Phase 20)
|------------------------------------------------------------------------------
|
|     GET  /api/ugc/{section}        one section's clips, through the allowlist
|     POST /api/ugc/{slug}/like      one like, from one browser, on one clip
|
| ── WHERE THIS IS REQUIRED FROM, AND WHY IT IS NOT routes/web.php ───────────
|
| `require __DIR__.'/ugc.php';` sits inside routes/api.php's SecurityHeaders
| group, beside the two files already required there — payments-webhooks.php and
| security-csp.php. THAT LINE IS IN THIS PACKAGE: CLAUDE.md reserves
| routes/web.php for the integrator and says nothing about routes/api.php, and
| the api group is where this belongs for two reasons of substance rather than
| convenience:
|
|   1. CSRF. A like is posted by a script on a cached storefront page. In the web
|      group that needs a token, and the token is minted per session — so the
|      first shopper served a cached page would post a stale one and get a 419.
|      The api group has no CSRF middleware, which is why /api/quiz and
|      /api/products/{slug}/reviews are already there.
|   2. COOKIE ENCRYPTION. The one-per-browser token is set and read by this
|      endpoint and by nothing else. In the api group EncryptCookies does not
|      run, so it is written plain and read plain — consistent. Split across the
|      two groups it would be written encrypted and read as ciphertext, which
|      fails silently: every like would look like a first like.
|
| routes/api.php is loaded by bootstrap/app.php's withRouting(api:), so these
| routes exist the moment the compiled route table is rebuilt — which is what
| database/migrations/2027_02_02_000002_clear_caches_ugc_rail.php is for. A route
| added by a package does nothing until that table is gone.
|
| ── BOTH ROUTES FAIL CLOSED ─────────────────────────────────────────────────
|
| Api\UgcController checks `moduleEnabled('shoppable_video')` — default FALSE —
| before anything else and 404s while it is off, and the like endpoint checks the
| `likes_on` control as well. So applying this package adds two routes that
| answer 404 to everybody until the owner turns the module on. There is no
| capability here and there must not be: these are shopper endpoints.
|
| ── THROTTLES, AND WHY THEY DIFFER ──────────────────────────────────────────
|
| The read is 120/minute: a page may carry several rails, a crawler may walk a
| /videos page, and the answer is cached rows.
|
| The write is 30/minute, and the RateLimiter ceiling inside the controller is 40
| an hour per address on top of it. Two limits because they bound different
| things: the throttle bounds a burst, the hourly ceiling bounds a day's worth of
| patient inflation of somebody's like count. Neither is the real rule — the
| unique index on (clip, token hash) is — but the index cannot stop a caller
| minting a new token for every request, and these can.
*/

use App\Http\Controllers\Api\UgcController;
use Illuminate\Support\Facades\Route;

Route::get('/ugc/{section}', [UgcController::class, 'section'])
    ->middleware('throttle:120,1')
    ->name('api.ugc.section');

/*
 * {slug} carries no constraint, deliberately: the controller takes a string and
 * answers the ordinary 404 for anything that is not a published clip. A numeric
 * constraint would turn /api/ugc/x/like into a route-not-found with a different
 * body, which is a second way to say "no" — and two ways to say no is an oracle.
 */
Route::post('/ugc/{slug}/like', [UgcController::class, 'like'])
    ->middleware('throttle:30,1')
    ->name('api.ugc.like');
