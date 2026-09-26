<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Block;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\UgcSection;
use App\Services\UgcRail;
use App\Services\UgcSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Shortcodes for page and post content — the WordPress habit, preserved.
 *
 *   [kbb_products]                                     latest 8, store skin
 *   [kbb_products skin="luxe" columns="3" limit="6"]
 *   [kbb_products category="serums"]
 *   [kbb_products brand="cosrx" orderby="popularity"]
 *   [kbb_products featured="1"]
 *   [kbb_products on_sale="1" limit="4" skin="ribbon"]
 *   [kbb_products ids="12,44,91"]
 *
 *   [kbb_block slug="free-shipping-note"]              a reusable HTML block
 *
 * Rendered server-side, so the output is crawlable and needs no JavaScript.
 */
final class Shortcodes
{
    /**
     * How deep a block may nest before this stops expanding.
     *
     * A block's own content goes back through render(), so a block that names
     * a second block works -- and a block that names ITSELF would recurse
     * until PHP ran out of stack and returned a 500 for the page, not for the
     * block. Two guards, because they fail differently: $stack catches a cycle
     * however long (a -> b -> a), the depth cap catches a chain that is merely
     * absurd (a -> b -> c -> d) and bounds the work one page render can do.
     */
    private const MAX_BLOCK_DEPTH = 3;

    /** Slugs currently being expanded, innermost last. */
    private static array $stack = [];

    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales', 'created_at',
    ];

    /** Replace every shortcode in a block of content. */
    public static function render(?string $content): string
    {
        if ($content === null || ! str_contains($content, '[kbb_')) {
            return (string) $content;
        }

        /*
         * Blocks first, products second, and the order is load-bearing: a
         * block may contain [kbb_products], so the block has to be expanded
         * before anything looks for a product shortcode. Done the other way
         * round the product grid inside a block would be emitted as literal
         * text, which is the failure this whole mechanism exists to avoid.
         */
        $content = (string) preg_replace_callback(
            '/\[kbb_block\b([^\]]*)\]/',
            fn ($m) => self::block(self::attributes($m[1])),
            $content
        );

        $content = (string) preg_replace_callback(
            '/\[kbb_products\b([^\]]*)\]/',
            fn ($m) => self::products(self::attributes($m[1])),
            $content
        );

        /*
         * Shoppable video rails, LAST — and the order is the same argument the
         * block/products order above makes. A block may contain [kbb_videos], so
         * blocks are expanded first; a video rail cannot contain a shortcode,
         * because its content is rows in `ugc_sections` rather than operator
         * markup, so nothing has to run after it.
         *
         * ▲ AND [kbb_products] IS RENDERED AS A VIEW, WHICH A GREP FOR THE BLADE
         * TAG CANNOT SEE. self::products() ends in view('components.product-grid'),
         * so that template has a caller nothing in resources/views/ points at —
         * which has caught a lane out before. This shortcode is the same shape:
         * resources/views/ugc/rail.blade.php is reached from here and from NOWHERE
         * ELSE, so a grep for 'ugc.rail' finds this file and a grep for the
         * partial's own name finds nothing at all.
         */
        return (string) preg_replace_callback(
            '/\[kbb_videos\b([^\]]*)\]/',
            fn ($m) => self::videos(self::attributes($m[1])),
            $content
        );
    }

    /**
     * [kbb_block slug="..."] -- one reusable HTML snippet, by handle.
     *
     * Renders NOTHING, and says nothing, in every case where the block is not
     * there to render: no slug, no such slug, or a slug whose block is a
     * draft. A shortcode that cannot resolve must not leave "[kbb_block
     * slug=...]" sitting in the middle of a published page for a shopper to
     * read, and it must not print an error either -- the storefront is not the
     * place to report an authoring mistake. The admin screen is, and Content ->
     * HTML Blocks shows every block's status beside its shortcode.
     *
     * DRAFT IS THE OFF SWITCH. It is the only way to pull a block off the site
     * without deleting it or editing every page that names it, so the filter
     * is applied here, on the read path, rather than trusted to the author.
     */
    private static function block(array $a): string
    {
        $slug = trim((string) ($a['slug'] ?? ''));

        if ($slug === '') {
            return '';
        }

        if (in_array($slug, self::$stack, true) || count(self::$stack) >= self::MAX_BLOCK_DEPTH) {
            return '';
        }

        // Cached per slug under the kbb.sc. prefix so the existing flush() --
        // already called from every admin write path that touches content --
        // clears these too, and so Cache::flush() is still never needed.
        $key = 'kbb.sc.block.' . md5($slug);
        self::remember($key);

        $block = Cache::remember($key, 600, function () use ($slug) {
            $row = Block::query()->published()->where('slug', $slug)->first(['content']);

            // false, not null: null is what Cache::remember() treats as "not
            // cached", so a miss on a slug nobody has created would re-query
            // on every single page view.
            return $row === null ? false : (string) ($row->content ?? '');
        });

        if ($block === false || $block === '') {
            return '';
        }

        self::$stack[] = $slug;

        try {
            return self::render($block);
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * [kbb_videos section="..."] — one shoppable video rail, anywhere.
     *
     * The owner's requirement in his own words: "can insert anywhere in the site,
     * products and pages etc via short code". Which is what this is: a page body,
     * a post body and an HTML block all go through render(), so a rail can sit in
     * any of them, as many times as the owner likes, in any order beside anything
     * else.
     *
     * ── IT RENDERS THE EMPTY STRING AND SAYS NOTHING, IN EVERY FAILURE ──────
     *
     * Module off, no `section`, no such handle, a draft section, a section
     * restricted to the other storefront, or a section every clip of which is
     * unpublished — all of them return ''. THE SAME RULE self::block() FOLLOWS,
     * and for the reason its docblock gives: a shortcode that cannot resolve must
     * not leave "[kbb_videos section=...]" in the middle of a published page for a
     * shopper to read, and it must not print an error either, because the
     * storefront is not where an authoring mistake is reported. Content →
     * Shoppable video → Sections is, and it shows every section's status beside
     * its shortcode.
     *
     * THE MODULE SWITCH IS CHECKED FIRST, BEFORE ANY QUERY. UgcRail::section()
     * checks it too — this is the second lock and the cheap one, because it means
     * a shop with the module off does not resolve the service out of the container
     * to be told no.
     *
     * ── NOT CACHED HERE ────────────────────────────────────────────────────
     *
     * Every other arm of this class wraps its work in Cache::remember. This one
     * does not, deliberately: App\Services\UgcRail already caches the rail's
     * content for ten minutes under its own index, keyed by handle, locale and
     * cap, and it is the layer that knows when to drop it (every UGC admin write
     * calls UgcRail::flush()). A second cache over the top would hold a rendered
     * string that Shortcodes::flush() clears and the UGC admin does not — so
     * editing a video would change the rail for ten minutes and then change it
     * back, which is the worst of both.
     */
    private static function videos(array $a): string
    {
        $handle = trim((string) ($a['section'] ?? ''));

        if ($handle === '') {
            return '';
        }

        $settings = app(UgcSettings::class);

        if (! $settings->enabled()) {
            return '';
        }

        /*
         * `limit` and `columns` are both ATTRIBUTES AN AUTHOR TYPES, so both are
         * validated against the same sets the admin screen offers and anything
         * else is dropped rather than passed through. Rule 5's "a select stores
         * one of its own options or the default", applied to a shortcode
         * attribute — which is a place a value arrives from outside just as much
         * as a POST body is.
         */
        $limit = isset($a['limit']) ? max(1, min(UgcSection::MAX_TILES, (int) $a['limit'])) : null;

        $columns = isset($a['columns']) && isset(UgcSettings::COLUMNS[(string) $a['columns']])
            ? (string) $a['columns']
            : null;

        $rail = app(UgcRail::class)->section(
            $handle,
            app()->getLocale(),
            $limit,
        );

        if ($rail['tiles'] === []) {
            return '';
        }

        /*
         * ONE STYLESHEET AND ONE SCRIPT PER PAGE, AND @once DOES NOT DO IT.
         *
         * Blade's @once is scoped to a RENDER CYCLE, and every rail on a page is
         * its own `view(...)->render()` call from here — so the counter is back at
         * zero by the time the second rail starts and the directive fires again.
         * Two rails on one page shipped the CSS twice and, worse, the SCRIPT twice:
         * that script registers a delegated document click listener and an
         * IntersectionObserver, so a second copy opens the player twice on one tap
         * and observes every tile twice. Measured directly — two rails, two
         * `id="kbb-ugc-style"`.
         *
         * A static on this class would be wrong in the other direction: it is
         * per-process, so in a queue worker or a test process the second page would
         * render no stylesheet at all. The container IS per request in production
         * and per test in the suite, which is exactly the scope wanted.
         */
        $first = ! app()->bound('kbb.ugc.assets');

        if ($first) {
            app()->instance('kbb.ugc.assets', true);
        }

        return view('ugc.rail', [
            'rail' => $rail,
            'conf' => $settings->all(),
            'withAssets' => $first,
            'columnsOverride' => $columns,
            // An author-supplied heading wins over the section's own, so the same
            // section can carry a different heading on two pages.
            'headingOverride' => isset($a['title']) ? (string) $a['title'] : null,
            /*
             * The like endpoint, with a PLACEHOLDER rather than a slug: one
             * rail is one URL and the script substitutes the tile's own slug.
             * Built with Url::to() so the /kbb-upgrade base path is honoured —
             * and __SLUG__ is safe to interpolate because Url::to() never sees a
             * value from outside.
             */
            'likeUrl' => Url::to('/api/ugc/__SLUG__/like'),
        ])->render();
    }

    /** Parse key="value" pairs, tolerating single quotes and bare values. */
    private static function attributes(string $raw): array
    {
        preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|(\S+))/', $raw, $m, PREG_SET_ORDER);

        $out = [];

        foreach ($m as $pair) {
            $out[strtolower($pair[1])] = $pair[3] !== '' ? $pair[3] : ($pair[4] !== '' ? $pair[4] : $pair[5]);
        }

        return $out;
    }

    private static function products(array $a): string
    {
        $limit = max(1, min(48, (int) ($a['limit'] ?? 8)));

        // Cached per attribute set: the same shortcode on a page renders the
        // same products for everyone until the catalogue changes. (Rule 27)
        $key = 'kbb.sc.products.' . md5(serialize($a));
        self::remember($key);

        $products = Cache::remember($key, 600, function () use ($a, $limit) {
            $q = Product::query()->select(self::CARD_COLUMNS)->visible()
                ->with(['brand:id,name,slug', 'categories:id,name,slug']);

            // Explicit ids keep the order they were written — a manual
            // selection is an editorial choice and must not be re-sorted.
            $manualIds = [];

            if (! empty($a['ids'])) {
                $manualIds = array_values(array_filter(array_map('intval', explode(',', $a['ids']))));
                $q->whereIn('id', $manualIds);
            }

            if (! empty($a['exclude'])) {
                $q->whereNotIn('id', array_filter(array_map('intval', explode(',', $a['exclude']))));
            }

            // A named source is shorthand for a set of filters.
            match ($a['source'] ?? '') {
                'bestsellers' => $q->orderByDesc('total_sales'),
                'new' => $q->latest('id'),
                'sale' => EffectivePrice::whereOnSale($q),
                'featured' => $q->where('featured', true),
                'top_rated' => $q->where('review_count', '>', 0)->orderByDesc('rating'),
                'in_stock' => $q->where('stock_status', 'instock'),
                default => null,
            };

            /*
             * min_price / max_price, in AED, against the price the shopper is
             * charged.
             *
             * TWO THINGS WERE WRONG HERE. The bound was built as
             * `(int) $a['min_price'] * 100`, and the cast binds tighter than
             * the multiply: `min_price="12.50"` became 12 * 100 = 1200, so half
             * the dirham was silently dropped off every bound with a decimal in
             * it. Fils::parse() is the house parser and never routes the digits
             * through a float; it answers null on anything it will not accept,
             * and an unparseable bound is dropped rather than turned into zero,
             * because a min of zero matches everything and a max of zero
             * matches nothing — both silent, both wrong.
             *
             * And the column expression was this file's own
             * `COALESCE(NULLIF(sale_price, 0), price)`, which ignored
             * sale_starts_at / sale_ends_at entirely: a sale scheduled for next
             * week was already discounting today's filter. EffectivePrice is
             * Product::effectivePrice() in SQL, window included, and is the
             * same expression the shop's own price facet now uses.
             */
            $min = ! empty($a['min_price']) ? Fils::parse((string) $a['min_price']) : null;
            $max = ! empty($a['max_price']) ? Fils::parse((string) $a['max_price']) : null;

            EffectivePrice::whereRange($q, $min, $max);

            if (! empty($a['category'])) {
                $slugs = array_map('trim', explode(',', $a['category']));
                $q->whereHas('categories', fn ($c) => $c->whereIn('slug', $slugs));
            }

            if (! empty($a['brand'])) {
                $slugs = array_map('trim', explode(',', $a['brand']));
                $q->whereHas('brand', fn ($b) => $b->whereIn('slug', $slugs));
            }

            if (! empty($a['featured'])) {
                $q->where('featured', true);
            }

            if (! empty($a['on_sale'])) {
                // Product::isOnSale() in SQL — see EffectivePrice::whereOnSale().
                EffectivePrice::whereOnSale($q);
            }

            // An explicit order wins; otherwise the source's own order stands.
            $dir = ('asc' === strtolower((string) ($a['order'] ?? ''))) ? 'asc' : 'desc';

            if (isset($a['orderby']) || empty($a['source'])) {
                match ($a['orderby'] ?? 'date') {
                    'popularity' => $q->orderByDesc('total_sales'),
                    'rating' => $q->orderByDesc('rating'),
                    // The charged price, matching the min_price / max_price
                    // filter above and the shop's own sort. Ordering on the
                    // `price` column put a markdown where its pre-sale figure
                    // belonged.
                    'price' => EffectivePrice::orderBy($q, $dir),
                    'price-desc' => EffectivePrice::orderBy($q, 'desc'),
                    'name' => $q->orderBy('name', $dir),
                    'random' => $q->inRandomOrder(),
                    'menu_order' => $q->orderBy('position'),
                    // 'date' and anything unrecognised: `id`, which the block
                    // below applies to every branch anyway.
                    default => null,
                };
            }

            /*
             * `id` LAST, WHATEVER THE AUTHOR ASKED FOR.
             *
             * Every branch above ends in a LIMIT, and every key any of them
             * sorts on ties: `total_sales` across the tail, `rating` and
             * `review_count` at 0 for most of this catalogue (so `top_rated`
             * is very nearly one undifferentiated block), `position` at 0
             * until somebody reorders something, and the effective price
             * wherever two products cost the same. A LIMIT over a tie is a
             * truncation the database gets to decide, so the same [kbb_products]
             * tag renders different products into the same page with nothing
             * behind the change -- and these are cached, so whichever answer
             * the rebuild happened to get is then the answer for everyone.
             *
             * `random` is exempt: an author who asked for an arbitrary order
             * has asked for exactly the thing a total order removes. A manual
             * `ids` selection is restored to its written order below and is
             * unaffected either way.
             */
            if (($a['orderby'] ?? null) !== 'random') {
                $q->orderBy('id', $dir);
            }

            $rows = $q->limit($limit)->get();

            // Restore the written order for a manual selection.
            if ($manualIds !== [] && ! isset($a['orderby'])) {
                $rows = $rows->sortBy(fn ($p) => array_search($p->id, $manualIds, true))->values();
            }

            return $rows;
        });

        if ($products->isEmpty()) {
            return '';
        }

        return view('components.product-grid', [
            'products' => $products,
            'skin' => $a['skin'] ?? null,
            'columns' => isset($a['columns']) ? (int) $a['columns'] : null,
            'columnsMobile' => isset($a['columns_mobile']) ? max(1, min(2, (int) $a['columns_mobile'])) : null,
            'heading' => $a['title'] ?? null,
            'subheading' => $a['subtitle'] ?? null,
            'moreUrl' => $a['link'] ?? null,
            'moreLabel' => $a['link_text'] ?? 'View all',
        ])->render();
    }

    /** Track which keys we created, so flushing does not wipe unrelated caches. */
    private static function remember(string $key): void
    {
        $index = (array) Cache::get('kbb.sc.index', []);

        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put('kbb.sc.index', array_slice($index, -200), 86400);
        }
    }

    /**
     * Call after catalogue writes.
     *
     * Clears only the shortcode entries. Cache::flush() would empty the whole
     * store — including sessions on some drivers, logging everyone out.
     */
    public static function flush(): void
    {
        foreach ((array) Cache::get('kbb.sc.index', []) as $key) {
            Cache::forget($key);
        }

        Cache::forget('kbb.sc.index');
    }
}
