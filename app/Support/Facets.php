<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Request;

/**
 * Shop facet URLs, matching the theme's scheme.
 *
 * The theme uses cat[] / brand[] / price / sale / instock / paged / orderby.
 * The live WooCommerce site uses filter_brands / orderby / page. Both are read;
 * the theme's are written. That keeps the design identical while every indexed
 * URL on kbeautybliss.com still resolves.
 */
final class Facets
{
    /** Sort options, verbatim from the theme's select. */
    public const SORTS = [
        'featured' => 'Featured',
        'popularity' => 'Best selling',
        'plow' => 'Price: low to high',
        'phigh' => 'Price: high to low',
        'rating' => 'Top rated',
        'date' => 'Newest',
        'name' => 'Name A–Z',
    ];

    /** Price buckets: label, min, max — in AED, as the theme declares them. */
    public const BUCKETS = [
        'u54' => ['Under AED 54', null, 54],
        '54-150' => ['AED 54 – 150', 54, 150],
        '150-300' => ['AED 150 – 300', 150, 300],
        '300p' => ['AED 300+', 300, null],
    ];

    /** Legacy names from the live site mapped onto the theme's. */
    private const ALIASES = [
        'filter_brands' => 'brand',
        'product_cat' => 'cat',
        'on_sale' => 'sale',
        'in_stock' => 'instock',
    ];

    /** WooCommerce sort values mapped onto the theme's. */
    private const SORT_ALIASES = [
        'menu_order' => 'featured',
        'price' => 'plow',
        'price-desc' => 'phigh',
    ];

    private static ?array $memo = null;

    /**
     * Everything currently selected, with legacy parameters folded in.
     * Memoised: the sidebar calls this once per link, and with 93 brands that
     * was 93 identical parses of one query string.
     */
    public static function active(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = ['cat' => [], 'brand' => [], 'price' => null, 'sale' => null, 'instock' => null];

        foreach (['cat', 'brand'] as $key) {
            $out[$key] = self::listParam($key);
        }

        foreach (self::ALIASES as $legacy => $canonical) {
            $value = Request::query($legacy);

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($canonical, ['cat', 'brand'], true)) {
                $out[$canonical] = array_values(array_unique(array_merge(
                    $out[$canonical],
                    array_filter(array_map('trim', explode(',', (string) $value)))
                )));
            } else {
                $out[$canonical] = (string) $value;
            }
        }

        foreach (['price', 'sale', 'instock'] as $key) {
            $v = Request::query($key);
            if ($v !== null && $v !== '') {
                $out[$key] = (string) $v;
            }
        }

        return self::$memo = $out;
    }

    /** Multi-select values arrive either as cat[]=a&cat[]=b or cat=a,b. */
    private static function listParam(string $key): array
    {
        $raw = Request::query($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('trim', $values)));
    }

    public static function isOn(string $key, string $value): bool
    {
        return in_array($value, (array) (self::active()[$key] ?? []), true);
    }

    public static function sort(): string
    {
        $v = (string) Request::query('orderby', 'featured');
        $v = self::SORT_ALIASES[$v] ?? $v;

        return array_key_exists($v, self::SORTS) ? $v : 'featured';
    }

    public static function columns(): string
    {
        $c = (string) Request::query('cols', '4');

        return in_array($c, ['2', '3', '4'], true) ? $c : '4';
    }

    public static function page(): int
    {
        return max(1, (int) Request::query('paged', Request::query('page', 1)));
    }

    /**
     * A facet URL. $toggle adds or removes from a multi-select; otherwise the
     * value is set outright (or cleared when null). Always returns to page 1 —
     * staying on page 7 of a filter you just changed usually shows nothing.
     */
    public static function url(string $key, ?string $value, bool $toggle = true): string
    {
        $params = self::currentParams();

        if ($toggle) {
            $current = (array) ($params[$key] ?? []);
            $params[$key] = in_array($value, $current, true)
                ? array_values(array_diff($current, [$value]))
                : array_merge($current, [$value]);
        } else {
            $params[$key] = $value;
        }

        unset($params['paged'], $params['page']);

        return self::build($params);
    }

    public static function pageUrl(int $page): string
    {
        $params = self::currentParams();
        $params['paged'] = $page > 1 ? (string) $page : null;

        return self::build($params);
    }

    public static function clearUrl(): string
    {
        return Url::to('/shop/');
    }

    /**
     * The canonical URL for the current listing view, given the page's own
     * clean base (the category's URL, or /shop/ for the general listing).
     *
     * Gated by the crawl_clean setting rather than unconditional, since
     * this specific decision — collapsing every filter/sort combination
     * onto one canonical — is an opinionated SEO strategy choice, not a
     * correctness fix the way having *some* real canonical at all is.
     * When off, every filtered/sorted/paginated URL self-references
     * itself in full, same as if this feature didn't exist.
     *
     * When on: any real filter (category, brand, price, sale, in-stock)
     * or a non-default sort collapses the canonical to the clean base —
     * research consistently recommends this as the lowest-risk signal
     * (Google still crawls and can still discover products through the
     * filtered view; ranking signals just consolidate onto the one clean
     * URL rather than fragmenting across every combination). Pagination
     * alone, with no other filter active, keeps its own self-referencing
     * canonical including the page number — current guidance is that
     * collapsing every paginated page onto page 1 risks Google simply
     * not discovering products that only appear on later pages, which is
     * a worse outcome than the crawl-budget cost of indexing them.
     */
    public static function canonicalUrl(string $cleanBaseUrl): string
    {
        if ((\App\Models\Setting::map()['crawl_clean'] ?? '1') !== '1') {
            // An absolute URL, built from site_url directly — Url::to() is
            // deliberately root-relative (correct for <a href> links, the
            // one thing it exists for), which would make a canonical tag
            // built from it a broken, domain-less URL. Matches the same
            // absolute-URL pattern Seo::render() itself already uses for
            // its own default canonical.
            $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');

            return $base . Request::getRequestUri();
        }

        $active = self::active();
        $hasFilter = !empty($active['cat']) || !empty($active['brand'])
            || $active['price'] !== null || $active['sale'] !== null || $active['instock'] !== null;
        $hasSort = self::sort() !== 'featured';

        if ($hasFilter || $hasSort) {
            return $cleanBaseUrl;
        }

        $page = self::page();

        if ($page > 1) {
            return $cleanBaseUrl . '?paged=' . $page;
        }

        return $cleanBaseUrl;
    }

    private static function currentParams(): array
    {
        $a = self::active();

        return [
            'cat' => $a['cat'],
            'brand' => $a['brand'],
            'price' => $a['price'],
            'sale' => $a['sale'],
            'instock' => $a['instock'],
            'orderby' => Request::query('orderby'),
            'cols' => Request::query('cols'),
            's' => Request::query('s'),
        ];
    }

    private static function build(array $params): string
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = is_array($value) ? implode(',', $value) : $value;
        }

        $base = Url::to('/shop/');

        return $clean === [] ? $base : $base . '?' . http_build_query($clean);
    }
}
