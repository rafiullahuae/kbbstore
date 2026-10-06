<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Services\SiteLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * SOLD-OUT PRODUCTS ON A LISTING: AS USUAL, AT THE VERY END, OR NOT AT ALL.
 *                                                                   (Lane SX)
 *
 * The owner: "i should have option on category / brands etc backend setting
 * page, where i can exclude the sold out products or show at very end."
 *
 * He asked for an OPTION, so every default is today's page: the shop default
 * ships "show", and a category or brand that has never been touched follows
 * it. Applying the package moves nothing until somebody picks.
 *
 * ── WHERE THE CHOICE LIVES ──────────────────────────────────────────────────
 *
 *   the shop     Appearance → Site layout → Product grid → "Sold-out
 *                products" (`layout_sold_out`, SiteLayout::SCHEMA).
 *   a category   `categories.sold_out_mode`; NULL = follow the shop.
 *   a brand      `brands.sold_out_mode`;     NULL = follow the shop.
 *
 * A column on the row each page ALREADY HOLDS: the category archive has the
 * Category, the brand page has the Brand, so reading the choice costs no
 * query, and it goes when the row goes. /super-sale/ draws from a category it
 * does not load; campaignMode() answers that from a tiny cached map instead.
 *
 * ── WHAT "SOLD OUT" MEANS HERE ──────────────────────────────────────────────
 *
 * `products.stock_status` is anything but 'instock' -- 'outofstock', and also
 * 'onbackorder', because on this shop a backorder CANNOT be bought: the cart
 * refuses `stock_status !== 'instock'` ("That product is sold out",
 * Store\CartController::add) and the card draws its "Sold out" label on the
 * same test (components/product-card.blade.php). So the products that move or
 * go are exactly the cards that say "Sold out". A variable product is judged
 * by its parent row, as the card judges it; the product page is where sizes
 * are. That is also what `Product::inStock()` and the shop's own "In stock"
 * filter already mean.
 *
 * ── WHAT EACH CHOICE DOES TO A QUERY ────────────────────────────────────────
 *
 *   show   nothing at all -- the SQL is byte-for-byte what it was.
 *   end    ONE sort key PREPENDED ahead of everything the page orders by. Each
 *          group keeps the page's own order (the curated scope order, then its
 *          tie-breaks), because a key placed first only splits the list in
 *          two; it decides nothing inside either half. Still a total order,
 *          so ?paged= batches neither repeat nor skip a product.
 *   hide   a WHERE, so the grid, the "N products" count, every ?paged= batch
 *          and the listing's ItemList JSON-LD are all built from the same
 *          smaller set. The product page itself is untouched -- it is still
 *          reachable and still in the sitemap; only listings drop it.
 */
final class SoldOut
{
    /** The column on `categories` and `brands`; NULL means "use the shop default". */
    public const COLUMN = 'sold_out_mode';

    public const SHOW = 'show';

    public const END = 'end';

    public const HIDE = 'hide';

    /** Every stored value. A select stores one of these or nothing. */
    public const MODES = [self::SHOW, self::END, self::HIDE];

    /** The per-category / per-brand select, '' = follow the shop. */
    public const SCOPE_OPTIONS = [
        '' => 'Use the shop default',
        self::SHOW => 'Show as usual',
        self::END => 'Show at the very end',
        self::HIDE => 'Hide from listings',
    ];

    /** A constant, so nothing a setting holds ever reaches the SQL. */
    private const SOLD_LAST = "CASE WHEN products.stock_status = 'instock' THEN 0 ELSE 1 END";

    private const CAMPAIGN_CACHE = 'kbb.soldout.categories';

    /** The shop-wide choice, always one of MODES. */
    public static function shopDefault(): string
    {
        $mode = app(SiteLayout::class)->get('sold_out');

        return in_array($mode, self::MODES, true) ? $mode : self::SHOW;
    }

    /** One category's or brand's choice, falling back to the shop's. */
    public static function for(?Model $scope): string
    {
        $own = $scope?->getAttribute(self::COLUMN);

        return in_array($own, self::MODES, true) ? $own : self::shopDefault();
    }

    /**
     * What a value posted from a Catalog edit screen stores: one of MODES, or
     * NULL for "Use the shop default". Anything else is null too -- the
     * request rule refuses it first; this is the belt to that brace.
     */
    public static function clean(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::MODES, true) ? $raw : null;
    }

    /**
     * Apply a mode to a product listing query. Safe to call at any point in
     * building it: the "end" key is put at the FRONT of whatever ORDER BY the
     * query has or gets.
     */
    public static function apply(Builder $query, string $mode): Builder
    {
        if ($mode === self::HIDE) {
            return $query->where('products.stock_status', 'instock');
        }

        if ($mode === self::END) {
            $base = $query->getQuery();
            $base->orders = array_merge([['type' => 'Raw', 'sql' => self::SOLD_LAST]], (array) $base->orders);
        }

        return $query;
    }

    /**
     * The mode for /super-sale/'s campaign category, which that page names
     * by slug or id and never loads. A cached map of the categories that HAVE
     * a choice -- usually none -- so the page pays no query while warm.
     *
     * @param  array{0:string,1:string|int}  $campaign
     */
    public static function campaignMode(array $campaign): string
    {
        [$by, $value] = $campaign;
        $map = self::categoryChoices();
        $own = $map[$by === 'id' ? 'id' : 'slug'][(string) $value] ?? null;

        return in_array($own, self::MODES, true) ? $own : self::shopDefault();
    }

    /** @return array{id: array<string, string>, slug: array<string, string>} */
    private static function categoryChoices(): array
    {
        try {
            return Cache::rememberForever(self::CAMPAIGN_CACHE, static function (): array {
                $out = ['id' => [], 'slug' => []];

                foreach (Category::query()->whereNotNull(self::COLUMN)->get(['id', 'slug', self::COLUMN]) as $c) {
                    $out['id'][(string) $c->id] = (string) $c->getAttribute(self::COLUMN);
                    $out['slug'][(string) $c->slug] = (string) $c->getAttribute(self::COLUMN);
                }

                return $out;
            });
        } catch (\Throwable) {
            // Before the migration has run there is no column: the shop's own.
            return ['id' => [], 'slug' => []];
        }
    }

    /**
     * Whether `categories` / `brands` carry the column yet. The admin screens
     * ask, once per request, so a package applied before its migration has
     * run edits as it did rather than 500ing on a column that is not there.
     * Never asked on the storefront, which reads the attribute off a row it
     * already holds and gets NULL -- the shop default -- when it is absent.
     */
    public static function columnReady(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, self::COLUMN);
        } catch (\Throwable) {
            return false;
        }
    }

    /** A category was saved or deleted: the map above is rebuilt on next read. */
    public static function flush(): void
    {
        try {
            Cache::forget(self::CAMPAIGN_CACHE);
        } catch (\Throwable) {
            // An unreachable cache is not a failed category save.
        }
    }
}
