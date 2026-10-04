<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\GridSections;
use Illuminate\Support\Collection;

/**
 * Where the four OLDER homepage product rows get their products (Lane HC).
 *
 * The owner: "data queries to choose brand, category or mixed categories, or
 * manual section with search function properly" — for every section. The
 * Row 55 rails (Best Sellers, Trending, Under AED 54) already had a "Which
 * products" select and gained the mixed query in App\Support\HomeSections.
 * These four had none: HomeController::rails() hard-codes each one's query.
 *
 * EVERY KEY SHIPS AT WHAT THE SHOP ALREADY DOES. `src` is `auto` — the
 * hard-coded query, untouched — and `limit` is the number that query already
 * takes, so applying the package moves no byte (StorefrontEnglishUnchangedTest
 * is the instrument). Only a section whose source the owner changes reads
 * through GridSections::pool(), the same builder every other product section
 * on the page uses: one SELECT, visible() and the brand eager load included.
 *
 * Stored as ordinary HomepageContent settings — the bundles keys on the
 * existing Big savings bundles tab, the other three on a tab each — so the
 * Homepage content tabs and the All sections editor draw and save ONE value
 * through ONE endpoint.
 */
final class HomeSources
{
    /** section => [setting prefix, how many the shop shows, what "As shipped" means]. */
    public const RAILS = [
        'bundles' => ['hb', 8, 'Skincare sets (a category whose address has “set” in it), best sellers first — or the dearest products while there are none.'],
        'recommended' => ['rc', 5, 'Products ticked Featured in the product editor, best sellers first.'],
        'bestsellers' => ['bsl', 4, 'The best sellers, ranked by units sold.'],
        'flash' => ['fl', 4, 'Products on sale, best sellers first — or the newest products while nothing is on sale.'],
    ];

    public const SOURCES = [
        'auto' => 'As shipped',
        'query' => 'Brands and categories, mixed',
        'manual' => 'A list I pick myself, in my own order',
    ];

    public const LIMITS = ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '8' => '8', '10' => '10', '12' => '12', '16' => '16', '20' => '20', '24' => '24'];

    /** Spread at the END of the Big savings bundles block of HomepageContent::SCHEMA. */
    public const SCHEMA_BUNDLES = [
        'home_hb_src' => ['type' => 'select', 'label' => 'Which products', 'default' => 'auto', 'store' => 'setting', 'options' => self::SOURCES, 'help' => 'As shipped keeps the shop’s own choice, described above.'],
        'home_hb_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_hb_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_hb_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_hb_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_hb_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Search, add, then drag or use ↑ ↓ to order.'],
        'home_hb_limit' => ['type' => 'select', 'label' => 'How many', 'default' => '8', 'store' => 'setting', 'options' => self::LIMITS, 'help' => 'The shop shows 8.'],
    ];

    /** The other three, spread after HomeSections::SCHEMA. */
    public const SCHEMA = [
        'home_rc_src' => ['type' => 'select', 'label' => 'Which products', 'default' => 'auto', 'store' => 'setting', 'options' => self::SOURCES, 'help' => 'As shipped keeps the shop’s own choice, described above.'],
        'home_rc_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_rc_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_rc_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_rc_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_rc_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Search, add, then drag or use ↑ ↓ to order.'],
        'home_rc_limit' => ['type' => 'select', 'label' => 'How many', 'default' => '5', 'store' => 'setting', 'options' => self::LIMITS, 'help' => 'The shop shows 5.'],
        'home_bsl_src' => ['type' => 'select', 'label' => 'Which products', 'default' => 'auto', 'store' => 'setting', 'options' => self::SOURCES, 'help' => 'As shipped keeps the shop’s own choice, described above.'],
        'home_bsl_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_bsl_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_bsl_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_bsl_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_bsl_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Search, add, then drag or use ↑ ↓ to order.'],
        'home_bsl_limit' => ['type' => 'select', 'label' => 'How many', 'default' => '4', 'store' => 'setting', 'options' => self::LIMITS, 'help' => 'The shop shows 4.'],
        'home_fl_src' => ['type' => 'select', 'label' => 'Which products', 'default' => 'auto', 'store' => 'setting', 'options' => self::SOURCES, 'help' => 'As shipped keeps the shop’s own choice, described above.'],
        'home_fl_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_fl_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_fl_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_fl_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_fl_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Search, add, then drag or use ↑ ↓ to order.'],
        'home_fl_limit' => ['type' => 'select', 'label' => 'How many', 'default' => '4', 'store' => 'setting', 'options' => self::LIMITS, 'help' => 'The shop shows 4.'],
    ];

    /** The keys the bundles tab gains, in SCHEMA_BUNDLES order. */
    public const BUNDLE_KEYS = ['home_hb_src', 'home_hb_brands', 'home_hb_cats', 'home_hb_sort', 'home_hb_stock', 'home_hb_picks', 'home_hb_limit'];

    public const TABS = [
        'recommended' => ['Recommended for you', 'Which products this row shows and how many. As shipped: Products ticked Featured in the product editor, best sellers first.', ['home_rc_src', 'home_rc_brands', 'home_rc_cats', 'home_rc_sort', 'home_rc_stock', 'home_rc_picks', 'home_rc_limit']],
        'bestsellers' => ['Best sellers (ranked)', 'Which products this row shows and how many. As shipped: The best sellers, ranked by units sold.', ['home_bsl_src', 'home_bsl_brands', 'home_bsl_cats', 'home_bsl_sort', 'home_bsl_stock', 'home_bsl_picks', 'home_bsl_limit']],
        'flash' => ['Flash sale', 'Which products this row shows and how many. As shipped: Products on sale, best sellers first — or the newest products while nothing is on sale.', ['home_fl_src', 'home_fl_brands', 'home_fl_cats', 'home_fl_sort', 'home_fl_stock', 'home_fl_picks', 'home_fl_limit']],
    ];

    /**
     * One section's selection, cleaned, from the flat settings.
     *
     * @return array{source: string, limit: int, query: array, picks: list<int>}
     */
    public static function read(array $c, string $section): array
    {
        [$p, $shipped] = self::RAILS[$section];
        $src = (string) ($c["home_{$p}_src"] ?? 'auto');
        $limit = (string) ($c["home_{$p}_limit"] ?? '');

        return [
            'source' => isset(self::SOURCES[$src]) ? $src : 'auto',
            'limit' => isset(self::LIMITS[$limit]) ? (int) $limit : $shipped,
            'query' => ProductSource::clean([
                'brands' => $c["home_{$p}_brands"] ?? '',
                'cats' => $c["home_{$p}_cats"] ?? '',
                'sort' => $c["home_{$p}_sort"] ?? '',
                'stock' => (bool) ($c["home_{$p}_stock"] ?? false),
            ]),
            'picks' => ProductSource::ids($c["home_{$p}_picks"] ?? '', ProductSource::CAP_PICKS),
        ];
    }

    /** What changes the rows HomeController caches, as one string. */
    public static function signature(array $c): string
    {
        $sig = [];

        foreach (array_keys(self::RAILS) as $section) {
            $sig[$section] = self::read($c, $section);
        }

        return md5((string) json_encode($sig));
    }

    /**
     * The owner's choice for one section, or null for `auto` — the caller then
     * runs the query it has always run.
     */
    public static function chosen(array $c, string $section): ?Collection
    {
        $r = self::read($c, $section);

        if ($r['source'] === 'auto') {
            return null;
        }

        return app(GridSections::class)->pool($r['source'], 0, 0, $r['picks'], $r['limit'], null, false, $r['query'])->values();
    }
}
