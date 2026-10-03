<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One instance of the reusable homepage product-grid section (Lane GS).
 *
 * The owner asked for ONE section type he can add as many times as he likes,
 * each with its own heading, its own product selection and its own layout. This
 * is one of those instances; `App\Services\GridSections` is the service that
 * reads them, and `database/migrations/2027_05_11_000000_create_grid_sections_table.php`
 * carries the data decisions.
 *
 * ── THE FOUR OPTION SETS ARE CONSTANTS HERE, AND THAT IS LOAD BEARING ───────
 *
 * CLAUDE.md rule 5: "A select stores one of its own options or the default." A
 * source type, a layout and a column count are all selects, and the only way
 * that rule can be kept rather than intended is for the option set and the
 * validator to be the SAME list. These four constants are the list; they are
 * handed to `ModuleSchema` as `overrides` by `GridSections::fields()`, so the
 * picker the console draws and the cast the controller writes through are built
 * from one statement of what the options are — the arrangement
 * docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of doing twice.
 */
class GridSection extends Model
{
    /**
     * Where an instance's products come from.
     *
     * The six the owner named, in his order: "a brand · a category (with or
     * without its children) · a manual list he picks and orders himself · best
     * sellers · newest · on sale", plus `featured`, which the shop's own
     * `recommended` rail already uses and which is free to offer since the
     * column is indexed and the rail proves the query.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'bestsellers' => 'Best sellers — most units sold',
        /* Row 55 (Lane HA): the homepage's "Trending K-Beauty This Week".
           GridSections::trendingScores() says exactly what it counts. */
        'trending' => 'Trending — most ordered and viewed in the last 7 days',
        'newest' => 'Newest — most recently added',
        'onsale' => 'On sale — discounted right now',
        'featured' => 'Featured — the ones flagged in the product editor',
        'brand' => 'One brand',
        'category' => 'One category',
        'manual' => 'A list I pick myself, in my own order',
    ];

    /** Grid or carousel, chosen separately for desktop and for mobile. */
    public const LAYOUTS = [
        'grid' => 'Grid',
        'carousel' => 'Carousel — swipe sideways',
    ];

    /**
     * The column counts offered, desktop and mobile.
     *
     * Keys are strings because ModuleSchema's select options are a string-keyed
     * map and `cast()` compares with the key; an int key would be compared as
     * an int against a POSTed string and every value would be refused.
     *
     * Six on desktop is the ceiling because the card's own `--kbb-tile` floor
     * makes a seventh column narrower than the tile is designed for, and three
     * on mobile because a 390px viewport minus the gutter divided four ways is
     * 80px of card.
     */
    public const DESKTOP_COLS = ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'];

    public const MOBILE_COLS = ['1' => '1', '2' => '2', '3' => '3'];

    /** publish draws on the shop; draft is listed in the console and draws nothing. */
    public const STATUSES = ['publish' => 'Published', 'draft' => 'Draft — will not show'];

    protected $fillable = [
        'name', 'slug', 'status', 'position',
        'show_heading', 'heading', 'subheading',
        'source', 'source_brand_id', 'source_category_id', 'include_children', 'manual_ids',
        'count', 'mobile_count',
        'desktop_layout', 'desktop_cols', 'mobile_layout', 'mobile_cols',
        'skin', 'card_label', 'show_rank',
        'show_view_all', 'view_all_label', 'view_all_url',
    ];

    protected $casts = [
        'show_heading' => 'boolean',
        'include_children' => 'boolean',
        'show_view_all' => 'boolean',
        'show_rank' => 'boolean',
        'manual_ids' => 'array',
        'position' => 'integer',
        'count' => 'integer',
        'mobile_count' => 'integer',
        'desktop_cols' => 'integer',
        'mobile_cols' => 'integer',
        'source_brand_id' => 'integer',
        'source_category_id' => 'integer',
    ];

    /**
     * The key this instance occupies in `HomepageSections::registry()`.
     *
     * DERIVED FROM THE ID AND NEVER FROM THE SLUG, which is the whole reason it
     * is a method rather than a column. The key is what `homepage_sections`
     * stores an instance's order and Desktop/Mobile switches against; keyed off
     * the slug, renaming an instance would silently orphan both and the section
     * would jump to the end of the page with its switches back on.
     */
    public function sectionKey(): string
    {
        return self::keyFor((int) $this->id);
    }

    public static function keyFor(int $id): string
    {
        return 'grid_'.$id;
    }

    /**
     * The id inside a section key, or null when the key is not one of ours.
     *
     * `ctype_digit` and not `is_numeric`, for the reason `Banners::forHome()`
     * gives: `1e3`, ` 12` and `-4` are all numeric and none of them is an id
     * this shop ever issued.
     */
    public static function idFromKey(string $key): ?int
    {
        if (! str_starts_with($key, 'grid_')) {
            return null;
        }

        $raw = substr($key, 5);

        return ($raw !== '' && ctype_digit($raw) && (int) $raw > 0) ? (int) $raw : null;
    }

    /**
     * How many rows to FETCH: the larger of the two counts.
     *
     * The desktop and the mobile counts differ on purpose — the owner's second
     * instance is "5 columns on desktop and in mobile 6 products" — and the
     * cheap way to serve two numbers is to fetch the larger once and hide the
     * surplus at the other breakpoint with a class. One query, two answers; see
     * the partial's header for why that is a CSS answer and not a second read.
     */
    public function fetchCount(): int
    {
        return max(1, min(48, max((int) $this->count, (int) $this->mobile_count)));
    }
}
