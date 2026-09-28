<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The address scheme — collections, brands and the journal
|------------------------------------------------------------------------------
|
| Lane URL does not edit routes/web.php (CLAUDE.md, "Rules for parallel work"),
| so every route the scheme adds lives here. This file is ALREADY required from
| the very end of web.php, which is why the scheme needs no edit to web.php at
| all — see "WHAT THE INTEGRATOR HAS TO DO" at the bottom, which is nothing.
|
|
| THE SCHEME, decided before it was briefed. Plural for a LISTING page, singular
| for a DETAIL page — Google's own ecommerce URL guidance and the singular/plural
| intent studies agree, and it is why the product catalogue does not move:
|
|     today                            becomes
|     /product-category/{path}/   ->   /collections/{path}/
|     (a query string on /shop/)  ->   /brands/ and /brands/{slug}/
|     /product/{slug}/            ->   UNCHANGED
|     /skincare-guide/ + /{slug}/ ->   /blog/ and /blog/{slug}/
|
| App\Support\UrlScheme is where those shapes are written down. Nothing in this
| file spells one out.
|
|
| ONE MOVE, NEVER TWO. Every legacy route below points at the FINAL address:
|
|     /product-category/toners/        -> /collections/skincare/toners/   (one hop,
|         because CategoryArchiveController::show() resolves the canonical path
|         first rather than redirecting to /collections/toners/ and letting the
|         archive redirect again)
|     /korean-skincare-brands/         -> /brands/
|     /korean-skincare-brands/{slug}/  -> /brands/{slug}/
|     /brand/{slug}/                   -> /brands/{slug}/   (never via the other
|         legacy spelling)
|     /skincare-guide/                 -> /blog/
|     /skincare-guide/{slug}/          -> /blog/{slug}/
|     /post/{slug}                     -> /blog/{slug}/   (routes/kbb-journal-legacy.php)
|     /{slug}/                         -> /blog/{slug}/
|
|
| REGISTRATION ORDER IS LOAD-BEARING, and this file is required last for it.
| The final route below matches a single path segment at the site root, which is
| the shape of every storefront URL there is. Order is what keeps /cart reaching
| CartController rather than being read as an article called "cart".
|
| Order is not the only guard, because order is exactly what a later edit breaks
| silently. PageController::slugPattern() refuses every reserved first segment
| outright — `collections` was added to RESERVED_SLUGS with this change — so the
| root route cannot match /cart even if this file were one day required from the
| top. Both guards are tested, and the test walks the real router rather than a
| hand-written list: tests/Feature/RootSlugCollisionTest.php.
|
| (Route::fallback() is matched last by the router wherever it was registered,
| so being required after it is correct and does not shadow it.)
|
|
| WHAT THE INTEGRATOR HAS TO DO: NOTHING, and that is deliberate.
|
| Two lines in web.php already point at this lane's controllers, and both are
| correct as they stand because the METHODS were renamed rather than the routes:
|
|     Route::get('/product-category/{path}', [CategoryArchiveController::class, 'show'])
|     Route::get('/skincare-guide/',         [PageController::class, 'blog'])
|
| `show()` is now the 301 to /collections/…/ and `blog()` is now the 301 to
| /blog/. The pages themselves are `collection()` and `journal()`, registered
| below. Had it been done the other way round, the old addresses would have gone
| on serving the same pages the new ones do — two addresses, one canonical —
| until a web.php edit landed. This way the scheme is whole the moment the
| branch merges.
|
| Ships with database/migrations/2027_04_05_000000_clear_caches_url_scheme.php,
| because the host serves a compiled route table, and with
| ..._000100_url_scheme_redirect_rows.php, which repoints the stored rows that
| would otherwise chain or loop through the new addresses.
|
*/

use App\Http\Controllers\Store\BrandController;
use App\Http\Controllers\Store\CategoryArchiveController;
use App\Http\Controllers\Store\PageController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Collections — the category archives.
//
// `.*` because the path is the category's whole nested chain of slugs, four
// deep on this catalogue. Registered before the root catch-all at the end of
// this file; `collections` is also in PageController::RESERVED_SLUGS, so an
// editor cannot publish an article at an address this route owns.
// ---------------------------------------------------------------------------
Route::get('/collections/{path}', [CategoryArchiveController::class, 'collection'])
    ->where('path', '.*')->name('collection');

// ---------------------------------------------------------------------------
// Brands. /brands/ is the directory and /brands/{slug}/ is one brand's landing
// page — its name, logo, description and a preview of its catalogue.
//
// URL Contract U-05 is untouched: a brand's filterable, sortable, paginated
// product LISTING is still /shop/?filter_brands={slug}, which is what
// Brand::filterUrl() returns and what both pages link on to.
// ---------------------------------------------------------------------------
Route::get('/brands/', [BrandController::class, 'index'])->name('brands.index');
Route::get('/brands/{slug}/', [BrandController::class, 'show'])
    ->where('slug', '[A-Za-z0-9\-_]+')->name('brands.show');

// The retired brand addresses. A route rather than rows in the redirects table
// because it has to cover every brand, including ones created after any seeding
// migration ran. An unknown slug 404s, and the redirects table still gets its
// turn on that 404.
Route::get('/korean-skincare-brands/', [BrandController::class, 'legacyIndex']);
Route::get('/korean-skincare-brands/{slug}/', [BrandController::class, 'legacyShow'])
    ->where('slug', '[A-Za-z0-9\-_]+');
Route::get('/brand/{slug}/', [BrandController::class, 'legacyShow'])
    ->where('slug', '[A-Za-z0-9\-_]+');

// ---------------------------------------------------------------------------
// The journal. /blog/ is the index and /blog/{slug}/ is an article — a detail
// page under its listing's prefix, which is the scheme's own shape.
//
// This is also what closes an open defect: an article used to be served at the
// site root, where PageController::RESERVED_SLUGS owns the first segment, so a
// live article slugged `about`, `feed` or `brands` was an address this shop
// could never serve. Under /blog/ the article namespace is the shop's own and
// the reserved list stops eating into it.
// ---------------------------------------------------------------------------
/*
 * NOT ->name('blog'). web.php's /skincare-guide/ line already carries that name
 * and it is the integrator's to change; two routes with one name make
 * `route:cache` throw "Another route has already been assigned name [blog]",
 * which is a failure the compiled route table on the live host would show and
 * the test suite would not. `journal` is this page's name; nothing in the
 * application resolves either one, and the integrator may move `blog` onto this
 * line whenever web.php is next touched.
 */
Route::get('/blog/', [PageController::class, 'journal'])->name('journal');
Route::get('/blog/{slug}/', [PageController::class, 'post'])
    ->where('slug', '[A-Za-z0-9\-_]+')->name('post');

// The retired article prefix. Straight to /blog/{slug}/ — never via the root
// form, which would be a second hop.
Route::get('/skincare-guide/{slug}/', [PageController::class, 'legacyPost'])
    ->where('slug', '[A-Za-z0-9\-_]+');

// ---------------------------------------------------------------------------
// The article's WordPress address, at the site root with no prefix:
//   kbeautybliss.com/heartleaf-extract-transforming-k-beauty-skincare/
//
// 301 to /blog/{slug}/ for a published article, 404 otherwise — never a blanket
// redirect, which would make every mistyped root address a soft 404 wearing a
// 301. LAST, and constrained. See the header.
// ---------------------------------------------------------------------------
Route::get('/{slug}/', [PageController::class, 'rootArticle'])
    ->where('slug', PageController::slugPattern());
