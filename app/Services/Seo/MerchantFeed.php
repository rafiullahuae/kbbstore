<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Gtin;
use App\Support\Money;
use App\Support\ProductVisibility;
use App\Support\RichText;
use App\Support\SetEagerLoad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Google Merchant Center product feed. (Lane SEO)
 *
 * ── WHY A FEED FILE AND NOT THE CONTENT API ─────────────────────────────────
 *
 * The checklist the owner pasted recommends the Content API for Shopping via
 * google/apiclient. That API has been superseded by the Merchant API and its
 * sunset is announced for 2026; it would also need a service-account
 * credential, a new dependency and a worker to push from, and this host runs
 * no queue. A scheduled fetch of a URL is the oldest, most boring way Merchant
 * Center takes products, it needs none of that, and Meta Commerce Manager reads
 * the very same file as a "data feed" (RSS 2.0 with the g: namespace is one of
 * the formats it accepts).
 *
 * The shop had NO feed (WooCommerce's dies with WordPress at the domain move),
 * so this is new, at /feeds/google-merchant.xml, switched ON because the owner
 * asked for the important SEO work to be done (CLAUDE.md rule 1, 30 September)
 * and off again at Growth & Marketing → Google Shopping feed.
 *
 * ── WHAT IT PUBLISHES, AND NOTHING ELSE ─────────────────────────────────────
 *
 * An ALLOWLIST of Merchant Center attributes, each built from one named column
 * (CLAUDE.md's /api rule, applied to a public file): id, item_group_id, title,
 * description, link, image_link, additional_image_link (≤ 10), availability,
 * price, sale_price, sale_price_effective_date, brand, gtin or
 * identifier_exists=no, condition, product_type. No wc_id, no total_sales, no
 * stock count, no cost, no meta blob. A product is in the feed exactly when it
 * is in the sitemap: Product::scopeVisible() (published, visible, not
 * scheduled for later) and not noindex -- plus a price above zero, which
 * Merchant Center refuses anyway.
 *
 * A variable product is one item per variant, sharing item_group_id, each
 * with its own price and stock, because that is how Merchant Center wants
 * variants. Its link is the product page (the page has no per-variant address
 * to deep-link to).
 *
 * ── WHAT IT COSTS ───────────────────────────────────────────────────────────
 *
 * Nothing on any shop page: no listener, no hook, no per-request work. The
 * feed URL itself answers from cache. The cache key carries a STAMP read in
 * one query -- the newest updated_at and the row count of products and of
 * variants -- so an edit, a new product or a deletion through the editor
 * rebuilds it on the next fetch, and the entry lives an hour at most so a sale
 * window that opens or closes by the clock is picked up too. Writers that
 * bypass Eloquent (WebP's reference rewrite, a rename) call forget().
 *
 * A rebuild is chunked: one query per chunk for the products and one per
 * eager-loaded relation, plus one for the category tree -- the same count for
 * 3 products as for 300 (MerchantFeedTest measures both).
 */
final class MerchantFeed
{
    /** Settings key for the switch. Ships '1' -- see the class note. */
    public const SETTING = 'merchant_feed';

    /** The public address, relative to the site root. */
    public const PATH = '/feeds/google-merchant.xml';

    private const CACHE_PREFIX = 'kbb.merchant-feed.';

    /** Bumped by forget(); part of every cache key. */
    private const VERSION_KEY = 'kbb.merchant-feed.version';

    private const TTL = 3600;

    private const CHUNK = 200;

    /** Merchant Center's own limits. */
    private const MAX_TITLE = 150;

    private const MAX_DESCRIPTION = 5000;

    private const MAX_ID = 50;

    private const MAX_EXTRA_IMAGES = 10;

    public static function enabled(?array $settings = null): bool
    {
        return SeoSettings::from($settings ?? SeoSettings::map(), self::SETTING, '1') === '1';
    }

    /** The absolute feed URL to paste into Merchant Center. */
    public static function url(?array $settings = null): string
    {
        return self::base($settings ?? SeoSettings::map()) . self::PATH;
    }

    /** Drop every cached copy; the next fetch rebuilds from the live rows. */
    public static function forget(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The feed XML, from cache when nothing has changed.
     *
     * @return array{xml: string, items: int, products: int, built_at: string}
     */
    public function cached(): array
    {
        $s = SeoSettings::map();
        $key = self::CACHE_PREFIX . md5(implode('|', [
            self::stamp(),
            (string) Cache::get(self::VERSION_KEY, 0),
            self::base($s),
            Money::currency(),
        ]));

        $hit = Cache::get($key);

        if (is_array($hit) && isset($hit['xml']) && is_string($hit['xml'])) {
            return $hit;
        }

        $built = $this->build($s);
        Cache::put($key, $built, self::TTL);

        return $built;
    }

    /**
     * Build the whole document from the live rows.
     *
     * @return array{xml: string, items: int, products: int, built_at: string}
     */
    public function build(?array $settings = null): array
    {
        $s = $settings ?? SeoSettings::map();
        $base = self::base($s);
        $siteName = SeoSettings::firstFilled($s['seo_site_name'] ?? null, $s['store_name'] ?? null, 'K-Beauty Bliss');
        $paths = self::categoryPaths();
        $currency = Money::currency();

        $items = [];
        $products = 0;
        $ids = [];

        $query = Product::query()->visible()
            ->select(['id', 'slug', 'name', 'type', 'status', 'is_visible', 'published_at', 'sku', 'gtin', 'brand_id', 'category_id',
                'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'images',
                'short_description', 'description', 'seo', 'set_discount', 'set_price_mode', 'set_price_basis'])
            ->with([
                'brand:id,name',
                'categories:id',
                'variants' => fn ($q) => $q->select(['id', 'product_id', 'sku', 'price', 'sale_price', 'stock_status', 'image', 'position'])->orderBy('position')->orderBy('id'),
                'variants.attributeValues:id,name',
            ]);

        self::onlyExistingColumns($query);

        $query->chunkById(self::CHUNK, function ($chunk) use (&$items, &$products, &$ids, $base, $paths, $currency) {
            SetEagerLoad::on($chunk);

            foreach ($chunk as $product) {
                if (self::isNoindex($product->seo)) {
                    continue;
                }

                $rows = $this->itemsFor($product, $base, $paths, $currency, $ids);

                if ($rows !== []) {
                    $products++;
                    array_push($items, ...$rows);
                }
            }
        });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n"
            . '<channel>' . "\n"
            . '<title>' . self::x($siteName) . '</title>' . "\n"
            . '<link>' . self::x($base . '/') . '</link>' . "\n"
            . '<description>' . self::x($siteName . ' products') . '</description>' . "\n";

        foreach ($items as $item) {
            $xml .= "<item>\n";

            foreach ($item as $name => $value) {
                foreach ((array) $value as $one) {
                    $xml .= '<g:' . $name . '>' . self::x((string) $one) . '</g:' . $name . ">\n";
                }
            }

            $xml .= "</item>\n";
        }

        $xml .= "</channel>\n</rss>\n";

        return ['xml' => $xml, 'items' => count($items), 'products' => $products, 'built_at' => now()->toIso8601String()];
    }

    /**
     * The Merchant Center items for one product: one, or one per variant.
     *
     * @param  array<int, string>  $paths
     * @param  array<string, true>  $ids  ids already used in this feed
     * @return list<array<string, string|list<string>>>
     */
    private function itemsFor(Product $product, string $base, array $paths, string $currency, array &$ids): array
    {
        $name = trim((string) $product->name);

        if ($name === '' || trim((string) $product->slug) === '') {
            return [];
        }

        $images = self::images($product, $base);
        $common = [
            'description' => self::description($product, $name),
            'link' => $base . '/product/' . $product->slug . '/',
            'condition' => 'new',
        ];

        $brand = trim((string) ($product->brand?->name ?? ''));
        $categoryId = (int) ($product->category_id ?: ($product->categories->first()?->id ?? 0));
        $type = $paths[$categoryId] ?? '';

        $variants = $product->requiresVariant() ? $product->variants : collect();

        if ($variants->isEmpty()) {
            $regular = $product->compareAtPrice();
            $charged = $product->effectivePrice();

            if ($images === [] || $charged <= 0) {
                return [];
            }

            $gtin = Gtin::normalise(is_string($product->gtin) ? $product->gtin : null);

            return [self::item(
                self::uniqueId((string) $product->sku, 'kbb-' . $product->id, $ids),
                null,
                $name,
                $common,
                $images,
                (string) $product->stock_status,
                $regular,
                $charged,
                $currency,
                $product,
                $brand,
                $gtin !== null && Gtin::isValid($gtin) ? $gtin : null,
                $type,
            )];
        }

        $group = self::uniqueGroup((string) $product->sku, 'kbb-' . $product->id);
        $out = [];

        foreach ($variants as $variant) {
            /** @var ProductVariant $variant */
            $variant->setRelation('product', $product);
            $charged = $variant->effectivePrice();
            $regular = $variant->compareAtPrice();
            $label = trim($variant->label());

            $own = self::absolute(is_string($variant->image) ? $variant->image : null, $base);
            $variantImages = $own !== null ? array_values(array_unique(array_merge([$own], $images))) : $images;

            if ($variantImages === [] || $charged <= 0) {
                continue;
            }

            $out[] = self::item(
                self::uniqueId((string) $variant->sku, 'kbb-' . $product->id . '-' . $variant->id, $ids),
                $group,
                $label !== '' ? $name . ' - ' . $label : $name,
                $common,
                $variantImages,
                (string) $variant->stock_status,
                $regular,
                $charged,
                $currency,
                $product,
                $brand,
                null,
                $type,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $common
     * @param  list<string>  $images
     * @return array<string, string|list<string>>
     */
    private static function item(string $id, ?string $group, string $title, array $common, array $images, string $stock,
        ?int $regular, int $charged, string $currency, Product $product, string $brand, ?string $gtin, string $type): array
    {
        $regular = $regular !== null && $regular > $charged ? $regular : $charged;

        $item = ['id' => $id];

        if ($group !== null) {
            $item['item_group_id'] = $group;
        }

        $item += [
            'title' => mb_substr($title, 0, self::MAX_TITLE),
            'description' => $common['description'],
            'link' => $common['link'],
            'image_link' => $images[0],
        ];

        $extra = array_slice($images, 1, self::MAX_EXTRA_IMAGES);

        if ($extra !== []) {
            $item['additional_image_link'] = $extra;
        }

        // The same reading Seo::availability() gives the page's JSON-LD, so the
        // feed and the landing page cannot disagree: a back-order is not
        // something this shop sells, by the owner's decision.
        $item['availability'] = $stock === 'instock' ? 'in_stock' : 'out_of_stock';
        $item['price'] = Money::decimalString($regular) . ' ' . $currency;

        if ($charged < $regular) {
            $item['sale_price'] = Money::decimalString($charged) . ' ' . $currency;

            if ($product->sale_ends_at !== null) {
                $start = $product->sale_starts_at ?? now()->startOfDay();
                $item['sale_price_effective_date'] = $start->toIso8601String() . '/' . $product->sale_ends_at->toIso8601String();
            }
        }

        if ($brand !== '') {
            $item['brand'] = $brand;
        }

        if ($gtin !== null) {
            $item['gtin'] = $gtin;
        } else {
            // No manufacturer code on file. Merchant Center accepts the item
            // with this said explicitly, and disapproves it when it is not.
            $item['identifier_exists'] = 'no';
        }

        $item['condition'] = $common['condition'];

        if ($type !== '') {
            $item['product_type'] = $type;
        }

        return $item;
    }

    /** The shop's id for an item: its SKU when that is usable and unused, else a stable fallback. */
    private static function uniqueId(string $sku, string $fallback, array &$ids): string
    {
        $sku = trim($sku);
        $id = ($sku !== '' && mb_strlen($sku) <= self::MAX_ID && ! isset($ids[$sku])) ? $sku : $fallback;

        if (isset($ids[$id])) {
            $id = $fallback;
        }

        $ids[$id] = true;

        return $id;
    }

    private static function uniqueGroup(string $sku, string $fallback): string
    {
        $sku = trim($sku);

        return ($sku !== '' && mb_strlen($sku) <= self::MAX_ID) ? $sku : $fallback;
    }

    private static function description(Product $product, string $name): string
    {
        foreach ([$product->description, $product->short_description] as $html) {
            $text = RichText::toText(is_string($html) ? $html : null);

            if ($text !== '') {
                return mb_substr($text, 0, self::MAX_DESCRIPTION);
            }
        }

        return $name;
    }

    /**
     * Main image first, then the gallery: absolute, http(s) only, de-duplicated.
     * Read from the row as it is now, never from a stored copy of the list.
     *
     * @return list<string>
     */
    private static function images(Product $product, string $base): array
    {
        $out = [];
        $candidates = array_merge([$product->image], is_array($product->images) ? $product->images : []);

        foreach ($candidates as $candidate) {
            $abs = self::absolute(is_string($candidate) ? $candidate : null, $base);

            if ($abs !== null && ! in_array($abs, $out, true)) {
                $out[] = $abs;
            }
        }

        return $out;
    }

    /** The same rule Seo::absolute() and the sitemap apply, plus the scheme check. */
    private static function absolute(?string $path, string $base): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        $abs = str_starts_with($path, '/') && ! str_starts_with($path, '//') ? rtrim($base, '/') . $path : $path;
        $scheme = parse_url($abs, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $abs : null;
    }

    /**
     * id => "Skincare > Toners", for every category, from ONE query.
     *
     * @return array<int, string>
     */
    private static function categoryPaths(): array
    {
        $rows = [];

        foreach (Category::query()->get(['id', 'parent_id', 'name']) as $c) {
            $rows[(int) $c->id] = [(int) ($c->parent_id ?? 0), trim((string) $c->name)];
        }

        $out = [];

        foreach ($rows as $id => [$parent, $label]) {
            $trail = [$label];
            $seen = [$id => true];

            while ($parent !== 0 && isset($rows[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                array_unshift($trail, $rows[$parent][1]);
                $parent = $rows[$parent][0];
            }

            $out[$id] = implode(' > ', array_filter($trail, fn ($t) => $t !== ''));
        }

        return $out;
    }

    /** One query: has anything in the catalogue changed since the cached copy? */
    private static function stamp(): string
    {
        try {
            $row = DB::selectOne('select (select max(updated_at) from products) as pu, (select count(*) from products) as pc,'
                . ' (select max(updated_at) from product_variants) as vu, (select count(*) from product_variants) as vc');

            return implode('|', array_map('strval', (array) $row));
        } catch (\Throwable) {
            return 'none';
        }
    }

    private static function base(array $s): string
    {
        return rtrim(SeoSettings::firstFilled($s['site_url'] ?? null, (string) config('app.url')), '/');
    }

    private static function isNoindex(mixed $seo): bool
    {
        if (is_string($seo)) {
            $seo = json_decode($seo, true);
        }

        return is_array($seo) && ! empty($seo['noindex']);
    }

    /** Drop the columns a not-yet-migrated schema lacks, so the feed never 500s. */
    private static function onlyExistingColumns($query): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('products');
        $query->getQuery()->columns = array_values(array_filter(
            $query->getQuery()->columns ?? [],
            fn ($c) => in_array($c, $columns, true)
        ));
    }

    /** Text for an XML element: escaped, and stripped of bytes XML 1.0 forbids. */
    private static function x(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
