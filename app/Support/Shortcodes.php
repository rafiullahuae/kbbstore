<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
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
 * Rendered server-side, so the output is crawlable and needs no JavaScript.
 */
final class Shortcodes
{
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

        return (string) preg_replace_callback(
            '/\[kbb_products\b([^\]]*)\]/',
            fn ($m) => self::products(self::attributes($m[1])),
            $content
        );
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
                'sale' => $q->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price'),
                'featured' => $q->where('featured', true),
                'top_rated' => $q->where('review_count', '>', 0)->orderByDesc('rating'),
                'in_stock' => $q->where('stock_status', 'instock'),
                default => null,
            };

            if (! empty($a['min_price'])) {
                $q->whereRaw('COALESCE(NULLIF(sale_price, 0), price) >= ?', [(int) $a['min_price'] * 100]);
            }

            if (! empty($a['max_price'])) {
                $q->whereRaw('COALESCE(NULLIF(sale_price, 0), price) <= ?', [(int) $a['max_price'] * 100]);
            }

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
                $q->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price');
            }

            // An explicit order wins; otherwise the source's own order stands.
            $dir = ('asc' === strtolower((string) ($a['order'] ?? ''))) ? 'asc' : 'desc';

            if (isset($a['orderby']) || empty($a['source'])) {
                match ($a['orderby'] ?? 'date') {
                    'popularity' => $q->orderByDesc('total_sales'),
                    'rating' => $q->orderByDesc('rating'),
                    'price' => $q->orderBy('price', $dir),
                    'price-desc' => $q->orderByDesc('price'),
                    'name' => $q->orderBy('name', $dir),
                    'random' => $q->inRandomOrder(),
                    'menu_order' => $q->orderBy('position'),
                    default => $q->orderBy('id', $dir),
                };
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
