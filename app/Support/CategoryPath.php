<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * What /product-category/{path}/ should actually do with a path.
 *
 * THE BUG THIS EXISTS TO FIX
 *
 * routes/web.php resolves the archive like this:
 *
 *     $slug = basename(trim($path, '/'));
 *     return app(ShopController::class)->index($request, $slug);
 *
 * and ShopController does `Category::where('slug', $slug)->first()`, then
 * treats a null result as "no category filter applied". Three consequences,
 * all of them live, all asserted in CategoryPathContractTest:
 *
 *   - /product-category/does-not-exist/ returns 200, not 404, and renders the
 *     whole catalogue under the heading "Shop all". Every dead or mistyped
 *     category URL is a soft 404 serving duplicate content. An infinite space
 *     of them is crawlable.
 *
 *   - Only the last segment is ever read, so /product-category/made-up/
 *     nonsense/cleansers/ serves the Cleansers archive with a 200. The nested
 *     path is decoration; any prefix at all validates. That is unbounded
 *     duplicate content for every category on the site.
 *
 *   - Because a missing category silently becomes "Shop all", renaming a slug
 *     does not visibly break anything. The old URL keeps answering 200. Search
 *     engines keep it indexed, pointing at the wrong page, and nobody finds out.
 *
 * WHAT THIS RETURNS
 *
 * A verdict, not a response — the caller owns the HTTP layer:
 *
 *   ['status' => 'ok',       'category' => Category]  render the archive
 *   ['status' => 'redirect', 'to' => '/product-category/real/path/',
 *                            'to_path' => 'real/path', 'code' => 301]
 *   ['status' => 'notfound']                          404
 *
 * `to` is root-relative and is what the admin screens and the import map read.
 * `to_path` is the same destination as a BARE path, and it is what the caller
 * that is about to issue an HTTP redirect wants: redirectUrl() turns it into an
 * absolute URL with the trailing slash intact. See redirectUrl() for why the
 * two cannot be the same string.
 *
 * A canonical-prefix mismatch is a 301 rather than a 404 on purpose: the leaf
 * exists and the visitor plainly wants it, and those URLs have been answering
 * 200 for the life of the site, so some of them are indexed and linked. Moving
 * them to the real path with a 301 consolidates that into one address. A path
 * whose leaf does not exist at all has no destination to consolidate into, so
 * it gets the 404 it should always have had.
 */
final class CategoryPath
{
    /** Guard against a redirect loop left by two rows pointing at each other. */
    private const MAX_HOPS = 5;

    /**
     * @return array{status:string, category?:Category, to?:string, to_path?:string, code?:int}
     */
    public static function resolve(string $rawPath): array
    {
        $path = self::normalise($rawPath);

        if ($path === '') {
            return ['status' => 'notfound'];
        }

        $segments = explode('/', $path);
        $leaf = end($segments);

        $category = Category::query()->where('slug', $leaf)->first();

        if ($category !== null) {
            $canonical = self::canonicalPath($category);

            // The leaf is real but the ancestry it was asked for is not the
            // ancestry it has — either a stale link from before a move, or a
            // prefix someone invented. One address, one page: send it to the
            // real one.
            if ($canonical !== $path) {
                return [
                    'status' => 'redirect',
                    'to' => self::url($canonical),
                    'to_path' => $canonical,
                    'code' => 301,
                ];
            }

            return ['status' => 'ok', 'category' => $category];
        }

        // No such leaf. Before answering 404, ask whether this whole path used
        // to point somewhere — that is what category_redirects records.
        $target = self::followRedirect($path);

        if ($target !== null) {
            return [
                'status' => 'redirect',
                'to' => self::url(self::canonicalPath($target)),
                'to_path' => self::canonicalPath($target),
                'code' => 301,
            ];
        }

        return ['status' => 'notfound'];
    }

    /** "/Skincare/Face-Cleansers/" -> "skincare/face-cleansers" */
    public static function normalise(string $path): string
    {
        $path = trim($path, "/ \t\n\r\0\x0B");
        $path = strtolower($path);

        // Collapse empty segments so "a//b" and "a/b" are the same path rather
        // than two rows in the redirect table.
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        return implode('/', $segments);
    }

    /**
     * The category's real path.
     *
     * `path` is a cached column that CategoriesApiController recomputes on
     * every structural write, but a row imported from WooCommerce can carry a
     * null one, and a null path here would make canonicalPath() return '' and
     * redirect a perfectly good URL to nowhere. So the model's own walk is the
     * fallback, exactly as Category::url() does it.
     */
    public static function canonicalPath(Category $category): string
    {
        return self::normalise((string) ($category->path ?: $category->buildPath()));
    }

    /**
     * Root-relative archive URL, base path and trailing slash included.
     *
     * For an href. NOT for redirect() — see redirectUrl() below, which exists
     * because handing this to redirect() silently loses the trailing slash.
     */
    public static function url(string $path): string
    {
        return Url::to(self::archivePath($path));
    }

    /**
     * The same address, absolute, and safe to hand to redirect().
     *
     * ═════════════════════════════════════════════════════════════════════
     * WHY THIS IS NOT url(), AND WHY IT IS NOT redirect(url(...)) EITHER
     * ═════════════════════════════════════════════════════════════════════
     *
     * `redirect($relativePath)` hands the target to Laravel's UrlGenerator,
     * which STRIPS THE TRAILING SLASH. This shop's archive addresses keep one
     * (U-01, and Url's own class comment says why), so every 301 this class
     * produced landed on `/product-category/skincare/toners` — an address that
     * answers 200 and whose own `<link rel="canonical">` points at the slashed
     * form. The shop 301'd to an address that then declared a different one
     * canonical: a redirect hop and then a canonical hop, for every stale or
     * non-canonical category URL on the site.
     *
     * That is the identical defect `docs/GP-ADDRESSES-LAND.md` §5.3 measured
     * for the redirects TABLE and fixed there with `Url::redirect()`, which
     * made `CategoryArchiveController` the last producer of a 301 in this
     * application still doing it the other way. `CheckRedirects::handle()` and
     * `PageController::legacyPost()` are the other two, and both already go
     * through `Url::redirect()`.
     *
     * It takes the BARE path, not url()'s output, because `Url::redirect()`
     * applies the base path and the locale segment itself. Passing an
     * already-prefixed string would produce `/kbb-upgrade/kbb-upgrade/…` on the
     * staging mount — the exact trap `Url::redirect()`'s own comment records.
     *
     * The language matters as much as the slash: `Url::redirect()` goes through
     * `Url::to()`, so an Arabic reader following a stale link lands on the
     * Arabic archive instead of being dropped onto the English one.
     */
    public static function redirectUrl(string $path): string
    {
        return Url::redirect(self::archivePath($path));
    }

    /** The one place the archive's address shape is written down. */
    private static function archivePath(string $path): string
    {
        return '/product-category/' . $path . '/';
    }

    /**
     * Walk category_redirects to a live category, or null.
     *
     * Bounded: two rows that point at each other's paths would otherwise spin
     * here. A row whose category_id went null (its destination was deleted)
     * ends the walk with null, which the caller turns into a 404 — the right
     * answer, and the reason the FK is nullOnDelete rather than cascade.
     */
    private static function followRedirect(string $path): ?Category
    {
        $seen = [];
        $hops = 0;

        while ($hops++ < self::MAX_HOPS) {
            if (isset($seen[$path])) {
                return null;
            }

            $seen[$path] = true;

            $row = DB::table('category_redirects')->where('from_path', $path)->first();

            if ($row === null || $row->category_id === null) {
                return null;
            }

            $category = Category::query()->find((int) $row->category_id);

            if ($category !== null) {
                return $category;
            }

            return null;
        }

        return null;
    }

    /**
     * Record that `$fromPath` used to be `$category`, for the 301.
     *
     * Called by every write that can move a path: a slug edit, a re-parent, a
     * merge, a delete. Three rules, each one paid for:
     *
     *   - A path that is now live is never recorded. If a slug is renamed and
     *     then renamed back, the old row would otherwise 301 the live URL away
     *     from itself.
     *   - Rows pointing AT the old path are repointed to the new destination,
     *     so a category moved twice leaves one hop, not a chain.
     *   - updateOrInsert, not insert: `from_path` is unique, and a second move
     *     through the same path must overwrite rather than raise.
     */
    public static function record(string $fromPath, ?int $categoryId, string $reason = 'slug'): void
    {
        $fromPath = self::normalise($fromPath);

        if ($fromPath === '') {
            return;
        }

        // Never shadow a live category.
        $leaf = basename($fromPath);

        if (Category::query()->where('slug', $leaf)->exists()) {
            $live = Category::query()->where('slug', $leaf)->first();

            if ($live !== null && self::canonicalPath($live) === $fromPath) {
                DB::table('category_redirects')->where('from_path', $fromPath)->delete();

                return;
            }
        }

        if ($categoryId !== null && ! Category::query()->whereKey($categoryId)->exists()) {
            $categoryId = null;
        }

        // A redirect row points at a category BY ID, never at another path, so
        // a chain cannot form in the first place: one lookup always lands on
        // the destination or on nothing. followRedirect() still carries a hop
        // guard, but as belt and braces rather than because a chain is
        // reachable from here.
        DB::table('category_redirects')->updateOrInsert(
            ['from_path' => $fromPath],
            [
                'category_id' => $categoryId,
                'reason' => $reason,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
