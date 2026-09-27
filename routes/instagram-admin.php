<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Instagram  (Lane IG — Phase 21, Instagram Profile)
|------------------------------------------------------------------------------
|
| Our own Instagram account's recent posts, fetched in the admin, stored with the
| pictures on this shop's own disk, and drawn on the storefront from the cache.
| docs/IG-PROFILE.md is the whole design: §1 for what Meta permits for OUR OWN
| account, §7 for the six things the owner must do himself, §8 for what the
| "Configure now" button actually does.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/instagram            the screen's payload: the schema
|                                            tabs, the connection's state, the
|                                            §7 checklist and how many posts
|                                            there are. NO CREDENTIAL, ever.
|     POST   /admin-api/instagram            save the look and what a tile shows
|     POST   /admin-api/instagram/app        save the Meta app id and secret
|     GET    /admin-api/instagram/start      mint a state, go to Instagram
|     GET    /admin-api/instagram/callback   come back, exchange, store, fetch
|     POST   /admin-api/instagram/refresh    fetch the profile and posts again
|     DELETE /admin-api/instagram            disconnect (?posts=1 also clears)
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other Content route files. The exact line, and it
| belongs next to the `ugc-admin.php` require:
|
|     require __DIR__.'/instagram-admin.php';
|
| The guarded group is not a preference here, it is the whole security model.
| /api/* is unauthenticated (CLAUDE.md) and not one of these endpoints could live
| there: POST /app takes an app SECRET, /start mints an OAuth state, /callback
| stores a sixty-day access token, and DELETE can disconnect the shop from the
| account. `web` is also load-bearing rather than incidental — the OAuth state
| lives in the admin SESSION, so a stateless group would break the handshake and
| remove its CSRF defence in the same stroke.
|
| ── THE TWO GETs THAT WRITE, AND WHY THEY ARE GETs ──────────────────────────
|
| /start and /callback are both GET and both change state. That is not an
| oversight and it cannot be fixed by making them POSTs: an OAuth redirect is a
| top-level browser navigation, Instagram is the one doing the navigating, and a
| third party cannot be made to POST to us. So:
|
|   · App\Support\AdminCapabilities::RULES names BOTH BY NAME and maps them to
|     `instagram.manage`, ABOVE the general `GET admin-api/instagram/**` rule
|     that resolves to `instagram.view` — RULES is first-match-wins, so listed
|     the other way round a read capability would be enough to start an OAuth
|     handshake. That block carries the reasoning at length.
|   · the callback's CSRF defence is the single-use `state`, not the
|     VerifyCsrfToken middleware, which cannot apply to a GET arriving from
|     Instagram. App\Services\Instagram\InstagramAuth::consume() pulls the state
|     FIRST so every refusal has already spent it, and compares with hash_equals
|     after checking both sides are non-empty — because hash_equals('', '') is
|     true, which is the whole attack.
|
| ── ROUTE ORDER: THE NAMED PATHS BEFORE THE BARE ONE ────────────────────────
|
| `/instagram/app`, `/instagram/refresh`, `/instagram/start` and
| `/instagram/callback` are all registered before `/instagram` itself. Nothing
| here takes a path parameter, so no route can swallow another — this is ordering
| for the reader rather than for the router, and it matches the shape
| AdminCapabilityMapTest pins one layer down.
|
| ── THROTTLES, AND WHY THEY ARE NOT ALL THE SAME NUMBER ─────────────────────
|
| `throttle:60,1` on the reads and the saves, matching every other admin screen
| in this console. The two that reach a third party are tighter on purpose:
|
|   /refresh  throttle:12,1 — each press is up to 26 outbound calls to Meta (a
|             profile, a media page and one image download per post). A held-down
|             button should not be able to spend this shop's rate limit with
|             Instagram, because that limit is shared with the storefront's own
|             ability to ever refresh again.
|   /start    throttle:20,1 — each hit mints a state into the session. Cheap, but
|             there is no reason for a hundred a minute.
|   /callback throttle:20,1 — the state is single-use, so a replay is already
|             refused; this bounds the work a replayed URL can cause.
|
| ── AND CLEARING THE ROUTE CACHE IS NOT OPTIONAL ────────────────────────────
|
| A route added here does not exist until the compiled route table is rebuilt
| (CLAUDE.md). This round therefore ships
| database/migrations/2027_03_02_000000_clear_caches_instagram_profile.php, and
| the screen says so in as many words if it gets a 404 from its own endpoint.
*/

use App\Http\Controllers\Admin\InstagramController;
use Illuminate\Support\Facades\Route;

Route::post('/instagram/app', [InstagramController::class, 'saveApp'])
    ->middleware('throttle:60,1')
    ->name('admin.instagram.app');

Route::post('/instagram/refresh', [InstagramController::class, 'refresh'])
    ->middleware('throttle:12,1')
    ->name('admin.instagram.refresh');

Route::get('/instagram/start', [InstagramController::class, 'start'])
    ->middleware('throttle:20,1')
    ->name('admin.instagram.start');

Route::get('/instagram/callback', [InstagramController::class, 'callback'])
    ->middleware('throttle:20,1')
    ->name('admin.instagram.callback');

Route::get('/instagram', [InstagramController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.instagram');

Route::post('/instagram', [InstagramController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.instagram.save');

Route::delete('/instagram', [InstagramController::class, 'disconnect'])
    ->middleware('throttle:60,1')
    ->name('admin.instagram.disconnect');
