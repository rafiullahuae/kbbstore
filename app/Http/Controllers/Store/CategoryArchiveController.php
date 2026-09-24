<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\CategoryPath;
use Illuminate\Http\Request;

/**
 * /product-category/{nested/path}/ — the category archive.
 *
 * INTEGRATOR — ONE LINE. routes/web.php line 97 currently registers this URL as
 * a closure:
 *
 *     Route::get('/product-category/{path}', function (Request $request, string $path) {
 *         $slug = basename(trim($path, '/'));
 *         return app(ShopController::class)->index($request, $slug);
 *     })->where('path', '.*')->name('category');
 *
 * Replace it with:
 *
 *     Route::get('/product-category/{path}', [\App\Http\Controllers\Store\CategoryArchiveController::class, 'show'])
 *         ->where('path', '.*')->name('category');
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, which is why the
 * swap is described here rather than made. Everything else stays: same
 * ShopController, same filters, same grid, same `category` route name, same
 * `.*` constraint. The behaviour that changes is only what happens when the
 * path does not name a live category.
 *
 * WHAT THE CLOSURE DOES TODAY, and why it is worth a controller.
 *
 * `basename()` throws away every segment but the last, and ShopController
 * treats a category it cannot find as "no category filter". Three live
 * consequences, all of them pinned in CategoryPathContractTest against the
 * closure as well as against this class, so the test says what changed:
 *
 *   1. /product-category/does-not-exist/ answers 200 and renders the entire
 *      catalogue under the heading "Shop all". Every dead, mistyped or
 *      hallucinated category URL is a soft 404 serving duplicate content, and
 *      there is an unbounded supply of them for a crawler to find.
 *
 *   2. /product-category/complete/nonsense/cleansers/ answers 200 with the
 *      Cleansers archive. The nested path is decoration — any prefix at all
 *      validates — so every category on the site has infinitely many addresses.
 *
 *   3. Because a missing category silently becomes "Shop all", renaming a slug
 *      breaks nothing visibly. The old URL goes on answering 200 with the wrong
 *      page, indefinitely, and nobody finds out.
 *
 * Point 3 is why this ships alongside the redirect table rather than after it:
 * recording redirects is pointless while the old URL still answers 200 on its
 * own, because the redirect is never consulted.
 */
class CategoryArchiveController extends Controller
{
    public function show(Request $request, string $path)
    {
        $verdict = CategoryPath::resolve($path);

        /*
         * A stale or non-canonical path whose leaf still exists. 301, so the
         * link equity on the old address consolidates onto the real one.
         *
         * ─── CategoryPath::redirectUrl(), NOT redirect($verdict['to']) ──────
         *
         * This line used to read `redirect($verdict['to'], …)`, and that handed
         * a root-relative path to Laravel's UrlGenerator, WHICH STRIPS THE
         * TRAILING SLASH. So `/product-category/toners/` 301'd to
         * `/product-category/skincare/toners` — an address that answers 200 and
         * whose own `<link rel="canonical">` points at the slashed form. The
         * shop 301'd to an address that then declared a different one canonical,
         * which costs a crawler a redirect hop and then a canonical hop and
         * consolidates the link equity onto neither.
         *
         * Same defect, same fix as `docs/GP-ADDRESSES-LAND.md` §5.3 made for
         * the redirects TABLE: `Url::redirect()`. That package left this
         * controller as the last producer of a 301 in the application still
         * doing it the other way, and `tests/Feature/RedirectMiddlewareTest.php`
         * pinned the defect as-is with the reason written on it. This closes it;
         * the pin is advanced there with the old assertion quoted.
         *
         * It also fixes the language. `Url::redirect()` goes through
         * `Url::to()`, so an Arabic reader following a stale category link now
         * lands on the Arabic archive rather than being dropped onto the
         * English one.
         *
         * NOT A LOOP. The destination is `CategoryPath::canonicalPath()`, which
         * is a fixed point — resolve() answers `ok` for it, never `redirect` —
         * so this hop cannot bounce back here however many times a category is
         * re-parented. `CategoryPathContractTest` asserts the second request
         * answers 200 rather than another 301.
         */
        if ($verdict['status'] === 'redirect') {
            return redirect(CategoryPath::redirectUrl($verdict['to_path']), $verdict['code']);
        }

        // No such category, and no record of one ever having been there.
        // 404 is the honest answer and the one this URL should always have
        // given. Deliberately NOT a redirect to /shop/: a 301 from every dead
        // archive to the shop root is a soft 404 wearing a 301, and is treated
        // as one.
        if ($verdict['status'] === 'notfound') {
            abort(404);
        }

        // The slug, not the path, because that is what ShopController looks up
        // — and by here it is known to be the canonical one.
        //
        // The row goes with it. CategoryPath::resolve() has just fetched it to
        // decide between 200, 301 and 404, and without it ShopController ran
        // the identical `where slug = ?` a second time on every category
        // archive on the site.
        return app(ShopController::class)->index($request, $verdict['category']->slug, $verdict['category']);
    }
}
