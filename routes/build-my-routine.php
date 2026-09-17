<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Phase 10 — Build my routine: the storefront — Lane FM
|------------------------------------------------------------------------------
|
| Two public pages. The view is resources/views/store/routines.blade.php and the
| engine is App\Services\BuildMyRoutine.
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the integrator
| wires it up. This note is the record of where that one line belongs.
|
| INTEGRATOR — ONE LINE, at the STOREFRONT level of routes/web.php, NOT inside
| any group. Put it directly after the /reviews line:
|
|     Route::get('/reviews',     [PageController::class, 'reviewWall'])->name('review-wall');
|
|     // Phase 10 — Build my routine. Both pages 404 unless the module is on;
|     // it ships off. See routes/build-my-routine.php.
|     require __DIR__.'/build-my-routine.php';
|
| Resulting paths:
|
|     GET /routines                 the list of routines this shop can fill
|     GET /routines/{concern}       one routine, ?<role>=<product-slug> per swap
|
| NO GROUP, AND THAT IS DELIBERATE. These are storefront pages: no auth, no
| admin-api prefix, no NoStoreAdminApi. They read `products` through
| Product::scopeVisible() and scopeInStock() and publish nothing a shop page
| does not already publish — the card markup is the shop's own
| components/product-card, so the column allowlist question CLAUDE.md raises
| about /api/* does not arise here.
|
| ORDER MATTERS, for one specific reason. routes/web.php ends with a root
| catch-all — /{slug} — that serves blog posts from the site root. Registered
| after it, /routines would still win (Laravel matches in registration order and
| the catch-all is registered last), but the catch-all is not the only hazard:
| Store\PageController::RESERVED_SLUGS is the list that stops an EDITOR
| publishing a post whose slug is "routines" at an address this route already
| owns. 'routines' is added to it in this branch, and
| tests/Feature/RootSlugCollisionTest.php walks the registered routes and fails
| if a new static first segment is missing from that list — so this pair cannot
| come apart quietly.
|
| THE CACHE. database/migrations/2026_11_18_000002_clear_caches_build_my_routine.php
| ships with the package. On this host a route that is not in the compiled route
| table does not exist, and both of these would 404 for the wrong reason — which
| is indistinguishable from the module being off, and would cost somebody a
| morning.
|
| NAMES. `routines.index` and `routines.show`, which nothing else claims —
| checked against every ->name() in routes/. The storefront links between the
| two with Url::to(), not route(), because the shop is served under a base path
| (KBB_BASE_PATH=/kbb-upgrade on staging) and Url::to() is the helper the rest
| of the storefront already uses for exactly that.
*/

use App\Http\Controllers\Store\RoutineController;
use Illuminate\Support\Facades\Route;

Route::get('/routines', [RoutineController::class, 'index'])->name('routines.index');

/*
 * The concern is constrained to the shape of a slug rather than left open. It
 * is checked against App\Support\RoutineConcerns::exists() in the controller
 * either way — the pattern is not the validation — but an unconstrained
 * {concern} also matches "/routines/../../etc", and a 404 from the router is
 * cheaper than a 404 from a controller that had to boot the engine first.
 */
Route::get('/routines/{concern}', [RoutineController::class, 'show'])
    ->where('concern', '[a-z0-9-]+')
    ->name('routines.show');
