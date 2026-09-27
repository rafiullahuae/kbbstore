<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Which rows in `pages` the storefront actually serves, and at what address.
 * (Lane S9)
 *
 * ── WHY THIS EXISTS, AND WHY IT IS THE FIRST THING THE PAGE EDITOR ASKS ─────
 *
 * A content page is NOT reachable because it is in the `pages` table. It is
 * reachable because routes/web.php carries a literal route for it:
 *
 *     Route::get('/about', [PageController::class, 'show'])->defaults('slug', 'about');
 *
 * Seven of those exist, one per seeded page. The site-root catch-all — the one
 * that looks like it would pick up the rest — is `/{slug}/` →
 * `PageController::post()`, and that method queries `Post` and nothing else. So
 * a `pages` row whose slug is not one of the seven is a row NO REQUEST CAN EVER
 * REACH: it is listed in the admin with a green "published" pill and it 404s
 * for every reader and every crawler.
 *
 * That is not hypothetical. Store → Demo Content → Demo Blog Posts' sibling,
 * Demo Pages, writes four rows slugged `about-us-demo`, `shipping-delivery-demo`
 * and so on — none of them routed — under a comment that says "the whole point
 * of this demo content is to actually be visible so the page layout can be
 * previewed". Measured: all four 404.
 *
 * So the editor shows the truth about every row it lists, and it refuses to
 * create an eighth, because creating one would mean writing that same defect on
 * purpose. See App\Http\Controllers\Admin\PageEditorApiController's header.
 *
 * ── ASKED OF THE ROUTER, NOT OF A LIST ──────────────────────────────────────
 *
 * The slug is read off the route's own `defaults['slug']`, which is the exact
 * value PageController::show() receives — so the key identifies the ROW and the
 * value identifies the ADDRESS, and the two are allowed to differ. Register
 * `/about-us` with `->defaults('slug', 'about')` and this answers
 * `['about' => '/about-us/']`, which is what the shop really serves. A copy of
 * the seven slugs would have answered `/about/`, which would 404.
 *
 * Store\SeoFilesController::routedPagePaths() already asks the router the same
 * question for the sitemap, and its own header argues the case at length. This
 * is the same answer in a place a second caller can reach; ContentPageEditorTest
 * pins the two against each other by reflection, so they cannot drift.
 *
 * @see \App\Http\Controllers\Store\SeoFilesController
 */
final class RoutedPages
{
    /**
     * slug => path, both with slashes, for every content page the router serves.
     *
     * NOT memoised. The router is rebuilt per process and a test registers
     * routes into a live collection; a static cache here would answer a
     * question about the router as it was the first time somebody asked.
     *
     * @return array<string, string>
     */
    public static function paths(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! str_ends_with((string) $route->getActionName(), 'PageController@show')) {
                continue;
            }

            // A parameterised route cannot name one row, so it cannot say where
            // one row is served.
            if ($route->parameterNames() !== []) {
                continue;
            }

            $slug = (string) ($route->defaults['slug'] ?? '');
            $path = '/'.trim($route->uri(), '/').'/';

            if ($slug !== '' && $path !== '//' && ! isset($out[$slug])) {
                $out[$slug] = $path;
            }
        }

        return $out;
    }

    /** The address the storefront serves this row at, or null if it serves none. */
    public static function pathFor(string $slug): ?string
    {
        return self::paths()[$slug] ?? null;
    }
}
