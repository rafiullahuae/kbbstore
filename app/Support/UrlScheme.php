<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shop's address scheme, written down once.
 *
 * ── WHY A CLASS AND NOT FIFTY LITERALS ──────────────────────────────────────
 *
 * Before this existed, "/product-category/" was spelled out in the model, the
 * archive controller, the path resolver, the legacy-address helper, the
 * sitemap, the import map, the SEO audit, two menu builders and the admin
 * preview — ten writers of one fact. A scheme change then has ten places to
 * miss, and the one it misses is a canonical pointing at an address the shop
 * 301s away from, which is worse than not moving at all.
 *
 * Every method returns a RAW, PREFIX-FREE, LOCALE-FREE path with its trailing
 * slash (U-01). That is deliberately the same spelling `redirects.source` and
 * `redirects.target` use, so a value from here can be stored in a row, compared
 * against `getPathInfo()`, or handed to `Url::to()` / `Url::redirect()` to have
 * the base path and the language segment put on. Anything here that went
 * through `Url::to()` first would bake `/kbb-upgrade` into a stored row — the
 * trap `App\Services\Import\RedirectMap`'s class comment records in full.
 *
 * ── THE SCHEME, AND WHY IT IS THIS SHAPE ────────────────────────────────────
 *
 * Plural for a LISTING page, singular for a DETAIL page. That is the split
 * Google's own ecommerce URL guidance and the singular/plural search-intent
 * studies agree on, and it is why the product catalogue does not move: a
 * shopper searching one item types the singular and `/product/{slug}/` is
 * already that shape.
 *
 *   /collections/{path}/     category archive   (was /product-category/{path}/)
 *   /brands/                 brand directory    (was /korean-skincare-brands/)
 *   /brands/{slug}/          brand landing page (was /korean-skincare-brands/{slug}/)
 *   /product/{slug}/         product            UNCHANGED
 *   /blog/                   journal index      (was /skincare-guide/)
 *   /blog/{slug}/            article            (was /{slug}/ at the site root)
 *
 * ── THE LEGACY CONSTANTS ARE PART OF THE CONTRACT, NOT LEFTOVERS ────────────
 *
 * Each `LEGACY_*` value is an address Google holds today. They are named here
 * rather than left as literals in the route file because three different things
 * have to agree about them: the route that 301s, the migration that repoints
 * stored rows so no chain forms, and the test that proves both. A fourth
 * spelling of `/korean-skincare-brands/` is a redirect that silently does not
 * fire.
 */
final class UrlScheme
{
    /** Category archives — a listing, so plural. */
    public const COLLECTION_BASE = '/collections/';

    /** What WooCommerce and this app served category archives at until now. */
    public const LEGACY_COLLECTION_BASE = '/product-category/';

    /** The brand directory and the per-brand landing pages. */
    public const BRAND_BASE = '/brands/';

    /** The brand directory's previous address (Phase 9's answer). */
    public const LEGACY_BRAND_INDEX = '/korean-skincare-brands/';

    /** The mega menu's per-brand leaf, from before brand pages existed. */
    public const LEGACY_BRAND_BASE = '/brand/';

    /** The journal index and every article. */
    public const BLOG_BASE = '/blog/';

    /** The journal index's previous address. */
    public const LEGACY_BLOG_INDEX = '/skincare-guide/';

    /**
     * Products. Named for completeness and because `productChanged()` below is
     * the honest answer to "does the catalogue need redirect rows" — not a
     * literal anybody has to trust.
     */
    public const PRODUCT_BASE = '/product/';

    /** `/collections/skincare/toners/` from `skincare/toners`. */
    public static function collection(string $path): string
    {
        return self::COLLECTION_BASE.trim($path, '/').'/';
    }

    /** The same archive at the address it used to be served from. */
    public static function legacyCollection(string $path): string
    {
        return self::LEGACY_COLLECTION_BASE.trim($path, '/').'/';
    }

    /** The brand directory. */
    public static function brandIndex(): string
    {
        return self::BRAND_BASE;
    }

    /** One brand's landing page. */
    public static function brand(string $slug): string
    {
        return self::BRAND_BASE.trim($slug, '/').'/';
    }

    /** The journal index. */
    public static function blogIndex(): string
    {
        return self::BLOG_BASE;
    }

    /** One article. */
    public static function article(string $slug): string
    {
        return self::BLOG_BASE.trim($slug, '/').'/';
    }

    /** One product. Unchanged by this scheme, and that is the point. */
    public static function product(string $slug): string
    {
        return self::PRODUCT_BASE.trim($slug, '/').'/';
    }

    /**
     * Did the product base move on THIS export, so that products need redirect
     * rows after all?
     *
     * ── WHY THIS IS A QUESTION AND NOT AN ASSUMPTION ────────────────────────
     *
     * The scheme leaves `/product/{slug}/` alone because WooCommerce's default
     * product base and this shop's U-01 are the same two words. But the base is
     * a SETTING: `woocommerce_permalinks['product_base']` — `manifest.json`
     * carries it verbatim — and a shop that ran `/shop/{slug}/` or
     * `/p/{slug}/` DOES need a row for every product it ever sold.
     *
     * So the answer is read off the export rather than argued. `null` or an
     * unreadable manifest means "no manifest said otherwise", which is not the
     * same as "it was /product": `RedirectMap::fromPermalinks()` still compares
     * each product's real exported permalink against its address here and
     * proposes a row whenever the two differ, so a missing manifest costs
     * nothing. This method exists so the REPORT can say which of the two
     * happened, and so the import screen can say it out loud.
     *
     * @param  array<string, mixed>|null  $manifest  the decoded manifest.json
     */
    public static function productBaseMoved(?array $manifest): bool
    {
        $base = $manifest['source']['woocommerce_permalinks']['product_base'] ?? null;

        if (! is_string($base) || trim($base) === '') {
            return false;
        }

        return '/'.trim($base, '/').'/' !== self::PRODUCT_BASE;
    }
}
