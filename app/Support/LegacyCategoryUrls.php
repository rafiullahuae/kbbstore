<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * The WooCommerce-era flat category addresses the navigation still carried,
 * and the one transformation that is safe to apply to them.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * kbeautybliss.com served its category archives at the site root — /toners/,
 * /sunscreens/, /cleansing-oils/ — because that is what its WooCommerce
 * permalink settings produced. This application serves them at
 * /product-category/{path}/ (URL Contract U-03), and nothing was ever taught
 * the difference. The menu rows seeded by
 * 2026_09_09_070000_fix_kbeautybliss_menu_structure kept the old shape, so
 * fifteen addresses in the header and the mobile drawer pointed at a URL
 * shape this application does not serve.
 *
 * They did not 404 in any way a reader could follow, either. routes/
 * kbb-brands-blog.php ends in `/{slug}/` — the single-segment catch-all that
 * serves a BLOG POST — so every one of them fell through to
 * PageController@post, which looked for an article called "toners", found
 * none, and 404'd. A shopper clicking "Toners" in the menu got the
 * not-found page; the application spent the request looking in the wrong
 * table entirely.
 *
 * ── WHY THE SLUG IS PRESERVED AND NOT REMAPPED ──────────────────────────────
 *
 * The obvious-looking repair — point /cleansing-oils/ at whichever category
 * the shop actually has today — is wrong here, and the reason is timing.
 *
 * The only categories on a freshly migrated database are the six placeholders
 * from DemoCatalogueSeeder (`cleansers`, `toners`, `serums`, `moisturisers`,
 * `sunscreens`, `masks`), which 2026_08_27_100000_seed_demo_catalogue runs on
 * EVERY install, production included. They are explicitly placeholders: the
 * real taxonomy arrives later, by WordPress import, carrying the live site's
 * own slugs — the very slugs in the list below.
 *
 * So a migration that resolved /cleansing-oils/ against the categories table
 * at apply time would, on a server where the import has not yet run, bake in
 * `cleansers` — and then the import would deliver the real `cleansing-oils`
 * category and the menu would go on pointing at a placeholder, permanently
 * and invisibly. Preserving the slug has the opposite failure mode, and it is
 * the benign one: the link 404s honestly until the category arrives, and
 * starts working by itself the moment it does, with nobody having to
 * remember to come back and fix it.
 *
 * CategoryPath::resolve() makes this better still. A leaf slug whose category
 * is nested under a parent does not 404 — it 301s to the canonical nested
 * path. So /product-category/cleansing-oils/ keeps working even if the
 * imported category turns out to live at skincare/cleansers/cleansing-oils.
 *
 * Categories the shop genuinely does not have are therefore REPORTED rather
 * than guessed at — see the migration, which prints them at apply time.
 */
final class LegacyCategoryUrls
{
    /**
     * Every flat category path the navigation was seeded with.
     *
     * This is the recognition list, and its exactness is what makes the
     * migration safe to re-run: a row whose URL is not one of these has
     * either been repaired already or been edited by the owner in Mega Menu,
     * and either way it is left alone.
     *
     * `/face-cleansers/` appears only in MenuDemo::tree(), as the parent of
     * Cleansing Oils and Face Washes. It is in the list because MenuDemo tops
     * up the mobile drawer when demo content is switched on, so it can reach
     * a real shopper.
     *
     * NOT in this list, and deliberately: /super-sale/,
     * /everything-under-54-aed/, /new-in/ and /best-sellers/ are flat root
     * URLs too, but they are COLLECTIONS with their own registered routes
     * (CollectionController) and they answer 200 today. /korean-skincare-brands/
     * and /skincare-guide/ likewise. Rewriting those would break them.
     *
     * @var list<string>
     */
    public const PATHS = [
        '/skincare/',
        '/face-cleansers/',
        '/cleansing-oils/',
        '/face-washes/',
        '/exfoliators/',
        '/toners/',
        '/face-serums/',
        '/eye-care/',
        '/face-masks/',
        '/moisturizers/',
        '/lip-care/',
        '/sunscreens/',
        '/hair-care/',
        '/skincare-sets/',
        '/beauty-devices/',
    ];

    /** Is this exactly one of the legacy flat category paths? */
    public static function isLegacy(string $url): bool
    {
        return in_array(self::normalise($url), self::PATHS, true);
    }

    /**
     * The address the same category lives at in this application.
     *
     * Slug preserved, shape corrected — see the note above for why that is
     * the whole transformation.
     */
    public static function toCategoryPath(string $url): string
    {
        return '/product-category/' . trim(self::normalise($url), '/') . '/';
    }

    /** The category slug a legacy path names. */
    public static function slugOf(string $url): string
    {
        return trim(self::normalise($url), '/');
    }

    /**
     * WHERE THIS OLD ADDRESS SHOULD LAND TODAY, or null if nothing may be
     * claimed about it.
     *
     * ── WHAT WAS BROKEN, AND IT IS THE WHOLE OF ROUND 1 ─────────────────────
     *
     * Measured against this application's own HTTP kernel, on a database with
     * the catalogue imported, before this method existed:
     *
     *     GET /toners/          404
     *     GET /skincare-sets/   404      (and two of the fifteen are confirmed
     *     GET /sunscreens/      404       indexed today, with their live titles
     *     ... all fifteen ...   404       -- see SeoAudit::scanLegacyAddresses)
     *
     * docs/CUTOVER-EXTRABEAUTY.md §4.1 forwards kbeautybliss.com PRESERVING
     * THE PATH, so each of those is a 301 from the retired domain that ends on
     * a 404. A ranking that should have been inherited is dropped instead, and
     * nothing on any screen says so. docs/SEO-SKINCARE-SETS-DUPLICATE.md
     * measured exactly this for one of the fifteen and said in as many words
     * that "nothing in the repo fixes it".
     *
     * `RedirectMap::fromLegacyRootCategories()` proposes rows for these, and
     * that remains the richer answer -- but it only runs when the owner walks
     * Store -> Import -> Addresses & pictures. A shop that has not been
     * through that screen has fifteen 404s and no warning. This method is the
     * answer that ships switched on, needs no screen, and needs no row.
     *
     * ── WHY A DERIVED ANSWER AND NOT FIFTEEN SEEDED ROWS ────────────────────
     *
     * A migration cannot write these. The class comment above says why at
     * length and the reason has not changed: on a freshly migrated database
     * the only categories are the six DemoCatalogueSeeder placeholders, so a
     * migration resolving /cleansing-oils/ at apply time would bake in a
     * destination that the real import then contradicts -- permanently and
     * invisibly. Deriving at request time has the opposite failure mode, and
     * it is the benign one: null until the category arrives, correct from the
     * moment it does, with nobody having to remember to come back.
     *
     * ── THE THREE RULES THIS METHOD EXISTS TO KEEP ──────────────────────────
     *
     * 1. NEVER INVENT A DESTINATION. The answer is CategoryPath::resolve()'s,
     *    which is a lookup against categories that exist. A slug this shop
     *    does not carry returns null and the visitor gets the honest 404 --
     *    never a confident 301 onto a page that is not the one they wanted.
     *    A wrong 301 transfers a ranking to the wrong address silently; a 404
     *    is at least visible.
     *
     * 2. NEVER END ON A 404, AND NEVER LOOP. resolve() answers `ok` only for a
     *    category that exists, and the two other statuses are handled here
     *    rather than passed on. CheckRedirects::loops() then walks the result
     *    the same way it walks a stored row, so a table row pointing back at
     *    one of the fifteen cannot build a cycle out of this.
     *
     * 3. ONE HOP. The destination is the CANONICAL nested path, not the flat
     *    /product-category/{slug}/ form that toCategoryPath() returns. Those
     *    two differ the moment a category has a parent: a nested `toners`
     *    under `skincare` makes /product-category/toners/ a 301 in its own
     *    right (CategoryPath::resolve, via CategoryArchiveController), so
     *    handing that address out would cost /toners/ -> /product-category/
     *    toners/ -> /product-category/skincare/toners/ -- two hops, which
     *    leaks ranking and burns crawl budget for nothing. canonicalPath() is
     *    the one-hop answer and is what this returns.
     *
     * ── THE GUARD THAT IS NOT OPTIONAL: THE SHOP'S OWN PAGE WINS ────────────
     *
     * routes/kbb-brands-blog.php ends in `/{slug}/`, which serves a published
     * ARTICLE at the site root -- the same namespace these fifteen addresses
     * live in. CheckRedirects now runs in the global pipeline, BEFORE the
     * router, so without this check an article published at the slug
     * "hair-care" would be shadowed by a 301 to a category archive and become
     * unreachable at its own address. docs/GP-ADDRESSES-LAND.md §10.4 names
     * this collision from the other direction -- "a redirect is not entitled
     * to take the address of a page the owner published" -- and before the
     * middleware was registered it was harmless, because the table was read
     * only on a 404. It is not harmless now.
     *
     * So a published post at this slug means null, and the article is served.
     *
     * ── COST ────────────────────────────────────────────────────────────────
     *
     * ZERO on every page of the shop. The in_array() against the fifteen
     * literals in PATHS answers first and answers in memory, so a storefront
     * URL never reaches a query -- StorefrontQueryBudgetTest is unmoved.
     * Only a request for one of the fifteen pays, and such a request renders
     * no page: it is a 301 or a 404 either way. That is the identical trade
     * CheckRedirects already makes for its own row SELECT and for its loop
     * walk, and it is why nothing here is cached -- a cache would buy nothing
     * on the pages that matter and would go stale exactly when a category is
     * renamed, which is the case this has to get right.
     *
     * @return string|null a root-relative path with no base path and no locale
     *                     segment -- the same spelling `redirects.target`
     *                     uses, so Url::redirect() can add both on the way out
     */
    public static function landingPath(string $url): ?string
    {
        if (! self::isLegacy($url)) {
            return null;
        }

        $slug = self::slugOf($url);

        if ($slug === '') {
            return null;
        }

        // Rule: the shop's own page wins. See the note above.
        if (Post::query()->where('slug', $slug)->where('status', 'published')->exists()) {
            return null;
        }

        $verdict = CategoryPath::resolve($slug);

        // `ok` -- the slug IS the canonical path, so the archive is one hop
        // away. `redirect` -- the leaf is real but lives under a parent, or a
        // category_redirects row moved it; `to_path` is the canonical path and
        // going straight there is what keeps this to one hop.
        $path = match ($verdict['status']) {
            'ok' => CategoryPath::canonicalPath($verdict['category']),
            'redirect' => (string) ($verdict['to_path'] ?? ''),
            default => '',
        };

        if ($path === '') {
            return null;
        }

        return '/product-category/' . $path . '/';
    }

    /**
     * landingPath() for all fifteen at once, in a BOUNDED number of queries.
     *
     * ── WHY THIS EXISTS AND IS NOT JUST A LOOP ──────────────────────────────
     *
     * SeoAudit::scanLegacyAddresses() has to know, for every one of the
     * fifteen, whether the address lands. It runs on an admin screen that
     * already scans the whole catalogue, and its own comment fixes the budget
     * in as many words: "ONE query, thirty bound values, two columns, however
     * many legacy paths there are. Not one query per path." Calling
     * landingPath() fifteen times would have quietly made it thirty to
     * forty-five, which is rule 4 — a budget is a budget — and the sort of
     * regression nobody sees because the screen still works.
     *
     * THREE queries, and three whatever the list grows to: the published posts
     * that would win the address, the categories that could answer it, and the
     * category_redirects rows that record a category having moved.
     *
     * ── AND THE TWO READERS CANNOT DRIFT ────────────────────────────────────
     *
     * This is a second implementation of one decision, which is exactly the
     * arrangement docs/GP-ADDRESSES-LAND.md §13.6 had to fix between
     * CanonicalHost and CheckRedirects: two copies of the same question
     * answering differently is a shop that redirects on one screen and not on
     * another. It is tolerated here only because the budget above makes it
     * necessary, and it is pinned by asserting this method's answer against
     * landingPath()'s for every one of the fifteen, rather than against a
     * literal — see SeoLegacyAddressesLandTest.
     *
     * @return array<string, string|null> path => where it lands, or null
     */
    public static function landingPaths(): array
    {
        $slugs = array_map(self::slugOf(...), self::PATHS);

        $taken = Post::query()
            ->whereIn('slug', $slugs)
            ->where('status', 'published')
            ->pluck('slug')
            ->all();
        $taken = array_fill_keys($taken, true);

        /** @var array<string, \App\Models\Category> $categories */
        $categories = Category::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug')
            ->all();

        // The moved-category case CategoryPath::followRedirect() answers. Keyed
        // on from_path, which for a bare root slug IS the slug.
        $moved = DB::table('category_redirects')
            ->whereIn('from_path', $slugs)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'from_path')
            ->all();

        $movedTargets = $moved === []
            ? []
            : Category::query()->whereIn('id', array_values($moved))->get()->keyBy('id')->all();

        $out = [];

        foreach (self::PATHS as $path) {
            $slug = self::slugOf($path);
            $out[$path] = null;

            if ($slug === '' || isset($taken[$slug])) {
                continue;
            }

            $category = $categories[$slug] ?? null;

            if ($category === null) {
                $id = $moved[$slug] ?? null;
                $category = $id === null ? null : ($movedTargets[(int) $id] ?? null);
            }

            if ($category === null) {
                continue;
            }

            $canonical = CategoryPath::canonicalPath($category);

            $out[$path] = $canonical === '' ? null : '/product-category/' . $canonical . '/';
        }

        return $out;
    }

    /**
     * Leading and trailing slash, so a row stored as `toners` or `/toners`
     * is recognised as the same address as `/toners/`.
     */
    private static function normalise(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_contains($url, '://')) {
            return $url;
        }

        // A query string or fragment means this is not a bare category path.
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return $url;
        }

        return '/' . trim($url, '/') . '/';
    }
}
