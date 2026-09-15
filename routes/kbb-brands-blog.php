<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Phase 9 — brands and the Journal, at the URLs the owner settled on
|------------------------------------------------------------------------------
|
| Lane B does not edit routes/web.php (CLAUDE.md, "Rules for parallel work"),
| so the routes live here and the integrator wires them in. This is not a
| drop-in addition: it REPLACES three existing lines in web.php, because the
| owner's answers reversed two choices an earlier pass had already made.
|
|
| STEP 1 — replace the brand block near the end of web.php.
|
|   Currently:
|       Route::get('/brands/', [BrandController::class, 'index'])->name('brands.index');
|       Route::get('/korean-skincare-brands/', [BrandController::class, 'legacyIndex']);
|       Route::get('/brand/{slug}/', [BrandController::class, 'legacyShow'])
|           ->where('slug', '[A-Za-z0-9\-_]+');
|
|   The owner's answer is that /korean-skincare-brands/ is the live address, so
|   index and legacyIndex swap places and a real per-brand page appears. Delete
|   those four lines; the replacements are in this file.
|
|
| STEP 2 — replace the article route (web.php, the journal block).
|
|   Currently:
|       Route::get('/skincare-guide/{slug}/', [PageController::class, 'post'])
|           ->where('slug', '[A-Za-z0-9\-_]+')->name('post');
|
|   Replace with the legacy redirect, which is in this file. The `post` NAME
|   moves onto the new root-level route, also in this file — keep the name
|   exactly, because web.php's own /post/{slug?} line redirects via
|   route('post', ...) and will then point at the new address for free.
|
|   The index line above it (/skincare-guide/ → blog) stays exactly as it is.
|   Only the article URL moved; the Journal index did not.
|
|
| STEP 3 — require this file from the VERY END of web.php, after every other
| route including Route::fallback():
|
|       require __DIR__ . '/kbb-brands-blog.php';
|
|   "At the very end" is not a style preference. The last route below matches a
|   single path segment at the site root, which is the shape of every storefront
|   URL there is. Registration order is what makes /cart reach CartController
|   rather than being read as a blog post called "cart". (Route::fallback() is
|   matched last by the router regardless of where it was registered, so putting
|   this after it is correct and does not shadow it.)
|
|   Order is not the only guard, because order is exactly what a later edit
|   breaks silently. PageController::slugPattern() refuses every reserved first
|   segment outright, so the root route cannot match /cart even if this file is
|   one day required from the top. Both guards are tested, and the test walks
|   the real router rather than a hand-written list — see
|   tests/Feature/RootSlugCollisionTest.php.
|
|
| STEP 4 — not a route, but it ships with this change.
|
|   app/Providers/AppServiceProvider.php (NOT Lane B's file) auto-creates a
|   redirect when a post slug changes, and still writes the old shape:
|
|       RedirectManager::autoCreate('/skincare-guide/' . $oldSlug . '/',
|                                   '/skincare-guide/' . $post->slug . '/');
|
|   Now that articles live at the root that needs to become:
|
|       RedirectManager::autoCreate('/' . $oldSlug . '/', '/' . $post->slug . '/');
|
|   Left as it is, every future slug change files a redirect between two URLs
|   that no longer exist, and the real one is never created.
|
*/

use App\Http\Controllers\Store\BrandController;
use App\Http\Controllers\Store\PageController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Brands. /korean-skincare-brands/ is the live address the owner confirmed;
// /brands/ (what the homepage publishes) and /brand/{slug}/ (the mega menu's
// leaves) 301 to it.
//
// URL Contract U-05 is untouched: a brand's filterable product listing is
// still /shop/?filter_brands={slug}, which is what Brand::url() returns and
// what both pages below link to.
// ---------------------------------------------------------------------------
Route::get('/korean-skincare-brands/', [BrandController::class, 'index'])->name('brands.index');
Route::get('/korean-skincare-brands/{slug}/', [BrandController::class, 'show'])
    ->where('slug', '[A-Za-z0-9\-_]+')->name('brands.show');

Route::get('/brands/', [BrandController::class, 'legacyIndex']);
Route::get('/brand/{slug}/', [BrandController::class, 'legacyShow'])
    ->where('slug', '[A-Za-z0-9\-_]+');

// ---------------------------------------------------------------------------
// The retired article URL. A route rather than rows in the redirects table
// because it has to cover every post, including ones written after the seeding
// migration ran. An unknown slug 404s, and the exception handler still checks
// the redirects table on that 404.
// ---------------------------------------------------------------------------
Route::get('/skincare-guide/{slug}/', [PageController::class, 'legacyPost'])
    ->where('slug', '[A-Za-z0-9\-_]+');

// ---------------------------------------------------------------------------
// The article itself, at the site root with no prefix — one slug per post,
// exactly as the live site serves it:
//   kbeautybliss.com/heartleaf-extract-transforming-k-beauty-skincare/
//
// LAST, and constrained. See STEP 3 above.
// ---------------------------------------------------------------------------
Route::get('/{slug}/', [PageController::class, 'post'])
    ->where('slug', PageController::slugPattern())
    ->name('post');
