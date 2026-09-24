<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Concern-led collections — /concern/acne/ and the seven that are not live yet.
 *
 * ── WHY THIS IS THE HIGHEST-RANKED THING IN THE SEO PLAN ────────────────────
 *
 * docs/SEO-BUILD-PLAN.md ranks this first, and docs/SEO-COMPETITIVE.md records
 * `/collections/acne` as the single most instructive thing observed on the
 * competitor's site. "korean skincare for acne" is a query with buying intent.
 * "new in" is not. This shop sorts its catalogue by what a product IS
 * (Cleansers, Toners, Serums) and had no page anywhere addressed to what a
 * shopper WANTS.
 *
 * ── THE MAPPING ALREADY EXISTED, AND THIS DOES NOT BUILD A SECOND ONE ───────
 *
 * The plan said this needed "product-to-concern mapping that only the owner
 * has", and assumed a new one would have to be invented. It does not:
 * `products.routine_concerns` has held exactly that since Lane FM, written by
 * **Catalog → Build my routine**, against the eight concerns in
 * App\Support\RoutineConcerns -- which are the skin quiz's own eight, kept
 * character-identical by QuizAndRoutinesShareOneConcernListTest.
 *
 * Inventing a second vocabulary here would give the shop two lists of concerns
 * that drift apart, and the day anything joins them the join fails silently on
 * the spellings that do not match. RoutineConcerns' own header makes that
 * argument at length. So these pages read the column the owner is already
 * filling in, and tagging a product for the routine builder tags it for this
 * too. There is nothing new for him to maintain.
 *
 * ── THE THIN-PAGE GUARD, WHICH IS THE POINT OF THE DESIGN ───────────────────
 *
 * SEO-BUILD-PLAN's own warning against this feature is the risk it carries:
 * "a concern collection with four products and no copy is worse than no page."
 * A near-empty listing that publishes itself as its own canonical, enters the
 * sitemap and invites Google in is a page that teaches Google this site has
 * thin pages -- and that judgement is not confined to the page that earned it.
 *
 * So a concern page DOES NOT EXIST until it is worth visiting. Two conditions,
 * both required, and both checked in one place:
 *
 *   1. IT HAS COPY. A concern with no heading and no sentence of its own is a
 *      search result with nothing to say. Copy is English written by a person,
 *      keyed in InterfaceStrings like every other storefront string, so it
 *      translates through the pipeline the shop already has. ENABLED lists the
 *      concerns that have it.
 *
 *   2. IT HAS PRODUCTS -- MIN_PRODUCTS of them, live and in stock, by the same
 *      visible() scope the shop and the sitemap use.
 *
 * Until both hold, /concern/<slug>/ is a 404, it is absent from the sitemap and
 * nothing links to it. Nothing is a "coming soon" page, because a coming-soon
 * page is the thin page with extra steps.
 *
 * ── SO WHAT DOES THE OWNER HAVE TO SUPPLY ───────────────────────────────────
 *
 * For `acne`, which ships with its copy written: tag products for "Acne &
 * blemishes" under **Catalog → Build my routine** until at least MIN_PRODUCTS
 * of them are live and in stock. The page then exists. Nothing else.
 *
 * For the other seven: the same tagging, plus one entry of English copy in
 * InterfaceStrings and the slug added to ENABLED -- three lines, no new
 * subsystem, which is what "configuration plus copy" was supposed to mean.
 *
 * MEASURE ONE BEFORE SHIPPING SIX. That is the plan's instruction and it is
 * repeated here because this class makes shipping the other seven look free.
 * It is not: seven thin pages cost more than one good one.
 */
final class ConcernCollections
{
    /**
     * The concerns that have copy written for them, and may therefore be live.
     *
     * ONE, DELIBERATELY. `acne` is the concern the competitor demonstrably
     * ranks a page for and the one with the clearest buying intent. The other
     * seven slugs are all valid RoutineConcerns and every one of them is a 404
     * until somebody writes its sentences.
     *
     * @var list<string>
     */
    public const ENABLED = ['acne'];

    /**
     * The fewest live, in-stock products a concern page may be built from.
     *
     * Three and not one: below three a "collection" is a list, and a shopper
     * who arrives from "korean skincare for acne" on a page with two products
     * bounces -- which is the signal that costs the ranking the page was built
     * to win. It is not a setting, because it is a floor rather than a
     * preference, and a knob here would be a knob whose wrong value is
     * invisible until Google has already seen the page.
     */
    public const MIN_PRODUCTS = 3;

    /** Every concern slug that could ever have a page. */
    public static function slugs(): array
    {
        return array_values(array_filter(
            RoutineConcerns::slugs(),
            static fn (string $slug): bool => in_array($slug, self::ENABLED, true)
        ));
    }

    /** Does this slug name a concern that has copy? Not the same as "is live". */
    public static function isEnabled(mixed $slug): bool
    {
        return is_string($slug) && in_array($slug, self::ENABLED, true);
    }

    /**
     * The products for a concern: visible, in stock, and tagged for it.
     *
     * ── WHY `LIKE` AND NOT `whereJsonContains` ─────────────────────────────
     *
     * `products.routine_concerns` is a TEXT column holding a json array, not a
     * json column -- the migration says so and gives the reason. whereJsonContains
     * needs a real json column and behaves differently on MySQL and SQLite,
     * which this project runs both of.
     *
     * The match is safe rather than merely convenient: the stored value is
     * always `json_encode` of a list of slugs, so every slug in it is wrapped
     * in double quotes, and a slug is `[a-z-]+` by construction of
     * RoutineConcerns::LIST. `%"acne"%` therefore matches the slug `acne` and
     * cannot match any other -- no slug in that list is a quoted substring of
     * another. The caller is a route parameter, so it is checked against
     * ENABLED before it ever reaches here.
     *
     * COST: one unindexed scan of the products table per page. At 671 rows
     * that is nothing, and it is one query rather than the whole-table read
     * into PHP that BuildMyRoutine does -- which would not paginate. If this
     * catalogue ever reaches the tens of thousands, the answer is a pivot
     * table, not an index on a LIKE.
     */
    public static function query(string $slug, array $columns = ['*']): Builder
    {
        return Product::query()
            ->select($columns)
            ->visible()
            ->where('stock_status', 'instock')
            ->where('routine_concerns', 'like', '%"' . $slug . '"%');
    }

    /** How many live products a concern has. */
    public static function count(string $slug): int
    {
        if (! self::isEnabled($slug)) {
            return 0;
        }

        return self::query($slug)->count();
    }

    /**
     * The same figure for SEVERAL concerns, in ONE query — Lane Q.
     *
     * ── WHY THIS EXISTS AT ALL ─────────────────────────────────────────────
     *
     * count() is one SELECT COUNT per concern, which was right while exactly
     * one concern was enabled. Two callers now want the figure for a whole set
     * at once: live(), which the sitemap asks on every build, and the skin
     * quiz, which asks it on a page whose query budget is TWO
     * (StorefrontQueryBudgetTest, 'skin quiz'). Eight enabled concerns would
     * have been eight queries on the sitemap and would have blown the quiz's
     * budget the day a second concern got copy. This is one query for any
     * number of them, so neither caller gets more expensive as the owner
     * enables more pages.
     *
     * ── WHY READING ROWS HERE IS NOT THE THING THIS CLASS WARNS ABOUT ──────
     *
     * query()'s note argues against "the whole-table read into PHP that
     * BuildMyRoutine does". This is not that. The WHERE keeps only rows that
     * are TAGGED for one of the concerns asked about, which is the 30-45 rows
     * the owner is being asked to tag rather than the catalogue, and it plucks
     * ONE column. An untagged shop reads nothing at all.
     *
     * The tally is RoutineConcerns::clean(), not the LIKE, because a row that
     * matched on `acne` still has to be attributed to the right concern when it
     * carries three of them. The LIKE narrows; clean() decides. Both agree by
     * construction — see query()'s note on why `%"slug"%` cannot cross-match —
     * and ConcernCollectionsTest pins that the two routes give the same number.
     *
     * Unknown slugs are dropped rather than returned as 0, for the same reason
     * RoutineConcerns::clean() drops them: they are not concerns this shop has.
     *
     * @param  list<string>  $slugs
     * @return array<string, int>  every asked-for concern that exists, slug => count
     */
    public static function counts(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(
            $slugs,
            static fn (mixed $s): bool => RoutineConcerns::exists($s)
        )));

        $out = array_fill_keys($slugs, 0);

        if ($slugs === []) {
            return $out;
        }

        $rows = Product::query()
            ->visible()
            ->where('stock_status', 'instock')
            ->where(static function (Builder $q) use ($slugs): void {
                foreach ($slugs as $slug) {
                    $q->orWhere('routine_concerns', 'like', '%"' . $slug . '"%');
                }
            })
            ->pluck('routine_concerns');

        foreach ($rows as $raw) {
            foreach (RoutineConcerns::clean($raw) as $slug) {
                if (array_key_exists($slug, $out)) {
                    $out[$slug]++;
                }
            }
        }

        return $out;
    }

    /**
     * Is there a page here at all?
     *
     * The ONE place both conditions are checked, so the route, the sitemap and
     * any future menu link cannot disagree about whether a page exists. A
     * sitemap listing a URL the router 404s is the specific defect this
     * arrangement exists to make impossible.
     */
    public static function isLive(mixed $slug): bool
    {
        return self::isEnabled($slug) && self::count((string) $slug) >= self::MIN_PRODUCTS;
    }

    /**
     * Every concern page that exists right now. Used by the sitemap.
     *
     * One query for all of them rather than one each — see counts(). The answer
     * is identical to asking isLive() slug by slug, and ConcernCollectionsTest
     * asserts exactly that against the router.
     */
    public static function live(): array
    {
        $counts = self::counts(self::slugs());

        return array_values(array_filter(
            self::slugs(),
            static fn (string $s): bool => ($counts[$s] ?? 0) >= self::MIN_PRODUCTS
        ));
    }

    /** This concern's URL path, with the trailing slash the canonical carries. */
    public static function path(string $slug): string
    {
        return '/concern/' . $slug . '/';
    }
}
