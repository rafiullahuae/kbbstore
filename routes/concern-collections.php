<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Concern-led collections  (Lane S, round 2)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, in the STOREFRONT
| section beside the four existing collection routes (`/new-in`,
| `/best-sellers`, `/super-sale`, `/everything-under-54-aed`):
|
|     require __DIR__ . '/concern-collections.php';
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. The wording above describes WHERE the
| require belongs rather than claiming whether it is there yet, so it stays true
| on both sides of that edit.
|
| Routes added:
|
|     GET /concern/{concern}/   a listing of the products tagged for one concern
|
| ONE ROUTE FOR ALL EIGHT CONCERNS, and that is the point of the design. The
| four existing collections take one line in routes/web.php each, with the key
| pinned by ->defaults(). Doing that here would mean a route line — and so a
| signed package, and so the clear_caches migration that has to ship with every
| route change on this host — every time the owner wanted another concern page.
| Adding `dryness` to this shop is now one entry of English copy and one slug in
| App\Support\ConcernCollections::ENABLED. This file does not change again.
|
| PUBLIC AND UNAUTHENTICATED, like every other storefront listing. What it
| serves is the shop's own shelf, which is what a shopper is meant to see.
|
| THE {concern} PARAMETER IS NOT TRUSTED. CollectionController::concern() calls
| ConcernCollections::isLive() before anything else and 404s on anything that is
| not one of the eight slugs in App\Support\RoutineConcerns::LIST with copy
| written for it AND enough live products behind it. The where() below is belt
| and braces: it keeps a URL full of punctuation from ever reaching a controller
| or a query, and it is the same [a-z-]+ shape the slugs are built to.
|
| WHY /concern/{slug}/ AND NOT A TOP-LEVEL SLUG. The four existing listings sit
| at the root — /new-in, /super-sale — because they were the header links that
| already existed. A prefix here buys two things: every concern is one route
| rather than eight, and the root namespace is not spent on eight more words
| that could one day collide with a category or a brand slug. "acne" is exactly
| the sort of word a category might want.
|
| THE TRAILING SLASH IS THE CANONICAL FORM, matching /shop/ and the four
| collections. CollectionController::seoCtx() builds the canonical with it and
| the sitemap lists it with it, so nothing advertises a URL the site redirects.
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| the compiled route cache on the production host does not know about a route
| until it is dropped. See
| database/migrations/2026_12_20_000000_clear_caches_concern_collections.php.
*/

use App\Http\Controllers\Store\CollectionController;
use Illuminate\Support\Facades\Route;

Route::get('/concern/{concern}/', [CollectionController::class, 'concern'])
    ->where('concern', '[a-z-]+')
    ->name('collection.concern');
