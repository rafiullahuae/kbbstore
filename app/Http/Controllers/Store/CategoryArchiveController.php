<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\CategoryPath;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * /collections/{nested/path}/ — the category archive — and the 301 that brings
 * every visitor of the old address there in ONE hop.
 *
 * ── THE ADDRESS MOVED, AND `show()` IS NOW THE OLD DOOR ─────────────────────
 *
 * The archive used to be served at `/product-category/{path}/`. The address
 * scheme (App\Support\UrlScheme) moved it to `/collections/{path}/` — a
 * listing page, so the address is plural — and this class carries both ends:
 *
 *   collection()  serves the archive at its new address
 *   show()        301s the old address onto the new one, in one hop
 *
 * WHY THE ARCHIVE IS THE METHOD THAT WAS RENAMED, AND NOT THE REDIRECT.
 * `routes/web.php` is the integrator's file and this lane may not edit it. The
 * line it already carries reads
 *
 *     Route::get('/product-category/{path}', [CategoryArchiveController::class, 'show'])
 *
 * so leaving `show()` as the archive would have meant asking for a web.php edit
 * before the old address redirected at all — and until that edit landed the
 * shop would have served the SAME page at two addresses while its canonical
 * named only one, which is the duplicate-content shape this whole lane exists
 * to end. Repurposing `show()` as the 301 makes that existing line correct on
 * the day this merges, with no edit to web.php anywhere in the change.
 *
 * ── ONE HOP, WHICH IS THE WHOLE POINT AND IS EASY TO GET WRONG ──────────────
 *
 * `show()` does NOT redirect `/product-category/toners/` to
 * `/collections/toners/`. It RESOLVES the path first, so a `toners` nested
 * under `skincare` goes straight to `/collections/skincare/toners/`. The naive
 * version costs two hops — old base to new base, then leaf to nested path —
 * and Google follows a chain grudgingly. `CategoryPath::resolve()` already
 * knows the canonical path, including through a `category_redirects` row left
 * by a rename, so one lookup answers it.
 *
 * ── WHAT THE CONTROLLER FIXED WHEN IT REPLACED A CLOSURE, WHICH STILL HOLDS ─
 *
 * routes/web.php once resolved the archive inline with `basename()` and handed
 * the last segment to ShopController, which falls back to the whole catalogue
 * for a slug it does not recognise. Three live consequences, all pinned in
 * CategoryPathContractTest:
 *
 *   1. /product-category/does-not-exist/ answered 200 and rendered the entire
 *      catalogue under the heading "Shop all". Every dead, mistyped or
 *      hallucinated category URL was a soft 404 serving duplicate content, and
 *      there is an unbounded supply of them for a crawler to find.
 *
 *   2. /product-category/complete/nonsense/cleansers/ answered 200 with the
 *      Cleansers archive. The nested path was decoration — any prefix at all
 *      validated — so every category on the site had infinitely many addresses.
 *
 *   3. Because a missing category silently became "Shop all", renaming a slug
 *      broke nothing visibly. The old URL went on answering 200 with the wrong
 *      page, indefinitely, and nobody found out.
 */
class CategoryArchiveController extends Controller
{
    /**
     * The archive itself, at `/collections/{path}/`.
     *
     * Registered in routes/kbb-brands-blog.php, which is required from the very
     * end of web.php.
     */
    public function collection(Request $request, string $path)
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
         * TRAILING SLASH. So `/collections/toners/` 301'd to
         * `/collections/skincare/toners` — an address that answers 200 and
         * whose own `<link rel="canonical">` points at the slashed form. The
         * shop 301'd to an address that then declared a different one canonical,
         * which costs a crawler a redirect hop and then a canonical hop and
         * consolidates the link equity onto neither.
         *
         * Same defect, same fix as `docs/GP-ADDRESSES-LAND.md` §5.3 made for
         * the redirects TABLE: `Url::redirect()`.
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

    /**
     * The retired `/product-category/{path}/` address.
     *
     * ONE HOP TO THE FINAL ADDRESS. resolve() is what makes that true: the
     * destination is the category's CANONICAL collections path, so a nested
     * category, a renamed one and a top-level one all cost the visitor exactly
     * one 301. Redirecting to `/collections/{the path as asked}/` instead would
     * be correct-looking and would chain through collection()'s own 301 for
     * every category that has a parent — which on this catalogue is most of
     * them.
     *
     * A path naming no category at all 404s here rather than being sent to
     * `/collections/{nonsense}/` to 404 there. A 301 onto a 404 tells a search
     * engine the address was replaced by nothing, which is strictly worse than
     * the 404 it replaces, and it is the same rule collection() applies above.
     */
    public function show(Request $request, string $path): RedirectResponse
    {
        $verdict = CategoryPath::resolve($path);

        $target = match ($verdict['status']) {
            'ok' => CategoryPath::canonicalPath($verdict['category']),
            'redirect' => (string) ($verdict['to_path'] ?? ''),
            default => '',
        };

        abort_if($target === '', 404);

        // Url::redirect() through CategoryPath, for the trailing slash, the base
        // path and the reader's language — the three things redirect() on a bare
        // relative path gets wrong. UrlScheme::collection() is the shape it builds.
        return redirect(CategoryPath::redirectUrl($target), 301);
    }
}
