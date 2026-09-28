<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Banners → Cards banner  (Lane BN — Phase 22)
|------------------------------------------------------------------------------
|
| A named set of picture cards, its own controls, and which set the homepage
| draws. app/Services/Banners.php is the module; the migration headers under
| database/migrations/2027_03_22_* carry the data decisions.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/banners                        the screen's payload
|     POST   /admin-api/banners                        save which set shows
|     POST   /admin-api/banners/module                 the module's own on/off
|     POST   /admin-api/banners/sets                   create a set
|     GET    /admin-api/banners/sets/{set}             one set and its cards
|     PUT    /admin-api/banners/sets/{set}             rename / restyle a set
|     POST   /admin-api/banners/sets/{set}/duplicate   copy it, as a draft
|     DELETE /admin-api/banners/sets/{set}             delete it and its cards
|     GET    /admin-api/banners/sets/{set}/preview     the row as the shop draws it
|     POST   /admin-api/banners/sets/{set}/cards       add a card
|     PUT    /admin-api/banners/cards/{card}           edit a card
|     DELETE /admin-api/banners/cards/{card}           delete a card
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other Appearance route files. The exact line,
| and it belongs next to the `homepage-content-admin.php` require:
|
|     require __DIR__.'/banners-admin.php';
|
| The guarded group is the security model and not a preference. CLAUDE.md:
| "/api/* is unauthenticated." Not one of these endpoints could live there —
| every one of them writes what the front page of the shop shows, and the
| preview renders a storefront template with a draft row's contents in it.
|
| ── THE CAPABILITY, AND WHY IT IS TWO AND NOT ONE ──────────────────────────
|
| App\Support\AdminCapabilities gains `banners.view` and `banners.manage`, and
| the RULES block names the WRITES ABOVE THE READS because that file is
| first-match-wins. Listed the other way round, `GET admin-api/banners/**`
| would resolve every one of these paths and a read capability would be enough
| to delete a set.
|
| It fails CLOSED by construction: `AdminCapabilities::for()` returns null for
| a route it does not recognise and EnforceAdminCapability turns a null into a
| 403 for everyone who is not an owner. A path added here tomorrow and never
| mapped is owner-only, not open.
|
| ── ROUTE ORDER: /cards/{card} BEFORE NOTHING, AND /sets/{set} BEFORE ──────
|
| `/banners/cards/{card}` and `/banners/sets/{set}` cannot swallow each other —
| the segment between is a literal — so the order here is for the reader. What
| DOES matter is that `/banners/module` is registered before nothing that could
| match it: there is no `/banners/{something}` route at all in this file, which
| is deliberate. Every parameterised path carries a literal segment first, so a
| set called "module" is impossible to confuse with the switch.
|
| ── THROTTLES ──────────────────────────────────────────────────────────────
|
| `throttle:60,1` throughout, matching every other admin screen in this console.
| Nothing here reaches a third party, nothing here is expensive, and the preview
| renders one Blade partial over one query.
|
| ── AND CLEARING THE ROUTE CACHE IS NOT OPTIONAL ───────────────────────────
|
| A route added here does not exist until the compiled route table is rebuilt
| (CLAUDE.md). This round therefore ships
| database/migrations/2027_03_22_000200_clear_caches_cards_banner.php.
*/

use App\Http\Controllers\Admin\BannerApiController;
use Illuminate\Support\Facades\Route;

Route::post('/banners/module', [BannerApiController::class, 'toggle'])
    ->middleware('throttle:60,1')
    ->name('admin.banners.module');

Route::post('/banners/sets', [BannerApiController::class, 'createSet'])
    ->middleware('throttle:60,1')
    ->name('admin.banners.sets.create');

Route::post('/banners/sets/{set}/duplicate', [BannerApiController::class, 'duplicateSet'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.sets.duplicate');

Route::post('/banners/sets/{set}/cards', [BannerApiController::class, 'createCard'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.cards.create');

Route::put('/banners/sets/{set}', [BannerApiController::class, 'updateSet'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.sets.update');

Route::delete('/banners/sets/{set}', [BannerApiController::class, 'destroySet'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.sets.destroy');

Route::put('/banners/cards/{card}', [BannerApiController::class, 'updateCard'])
    ->middleware('throttle:60,1')
    ->whereNumber('card')
    ->name('admin.banners.cards.update');

Route::delete('/banners/cards/{card}', [BannerApiController::class, 'destroyCard'])
    ->middleware('throttle:60,1')
    ->whereNumber('card')
    ->name('admin.banners.cards.destroy');

Route::get('/banners/sets/{set}/preview', [BannerApiController::class, 'preview'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.preview');

Route::get('/banners/sets/{set}', [BannerApiController::class, 'showSet'])
    ->middleware('throttle:60,1')
    ->whereNumber('set')
    ->name('admin.banners.sets.show');

Route::get('/banners', [BannerApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.banners');

Route::post('/banners', [BannerApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.banners.save');
