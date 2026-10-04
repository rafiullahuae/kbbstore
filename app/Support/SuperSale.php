<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which products /super-sale/ lists, and in what order. (Lane SS)
 *
 * The owner: "on /super-sale page, i want the same products sorting and same
 * positioning of the products. check it on kbeautybliss.com/super-sale/ …
 * ready this category 'Super Sale' we need to run the campaign."
 *
 * ── WHAT THE OLD SITE'S PAGE IS ─────────────────────────────────────────────
 *
 * kbeautybliss.com serves its product categories at the site root with the
 * category base stripped — /skincare/, /toners/ (App\Support\LegacyCategoryUrls
 * lists them) — and /super-sale/ is the WooCommerce product category "Super
 * Sale" at that same root: the catalogue export carries "Super Sale" as a
 * category on its products. A WooCommerce category archive in "Default
 * sorting" orders by `menu_order ASC, title ASC`, and on that site
 * `menu_order` is the global order the Rearrange Products plugin writes
 * (wp_rwpp_product_order). The importer lands that number in
 * `products.position`; Catalog → Catalog → Reorder edits it here exactly as
 * the plugin did there.
 *
 * So the same products in the same places is: the products in the category
 * slugged `super-sale`, ordered position, then name — with id last so the
 * order is TOTAL and LIMIT/OFFSET pages it cleanly (CollectionController's
 * docblock carries that argument).
 *
 * This shop's /super-sale/ was a different list altogether — every product
 * with a markdown, biggest discount first — which both dropped campaign
 * products that are not marked down and put them in another order.
 *
 * ── THE SWITCH, AND WHY IT FALLS BACK ───────────────────────────────────────
 *
 * Pages → Page banners → Super Sale products stores one of OPTIONS' keys or a
 * `category:<id>` of a category that exists. `auto` (what ships, because the
 * owner asked for it) is the `super-sale` category. If the chosen category is
 * missing or has no visible product, the page lists every reduced product as it
 * did before rather than drawing an empty campaign page — and that fallback
 * costs a query only on the shop where it happens.
 */
final class SuperSale
{
    public const KEY = 'super_sale_source';

    public const CATEGORY_SLUG = 'super-sale';

    public const OPTIONS = [
        'auto' => 'The “Super Sale” category, in its Reorder order (as the old site)',
        'on_sale' => 'Every reduced product, biggest discount first (the old behaviour here)',
    ];

    public const DEFAULT = 'auto';

    /** The stored choice, normalised to something this class can act on. */
    public static function source(SettingsService $settings): string
    {
        $v = (string) $settings->get(self::KEY, self::DEFAULT);

        return isset(self::OPTIONS[$v]) || preg_match('/^category:[1-9]\d{0,9}$/', $v) === 1 ? $v : self::DEFAULT;
    }

    /**
     * The category the listing draws from — ['slug', 'super-sale'] or
     * ['id', 12] — or null for "every reduced product".
     *
     * @return array{0:string,1:string|int}|null
     */
    public static function campaign(SettingsService $settings): ?array
    {
        $source = self::source($settings);

        if ($source === 'on_sale') {
            return null;
        }

        if (str_starts_with($source, 'category:')) {
            return ['id', (int) substr($source, 9)];
        }

        return ['slug', self::CATEGORY_SLUG];
    }

    /**
     * Narrow a product query to the campaign category and order it as the old
     * site did. One correlated EXISTS, so the select list, the pagination and
     * the brand eager-load are untouched — and the query count is the same as
     * the reduced-products listing it replaces.
     *
     * @param  array{0:string,1:string|int}  $campaign
     */
    public static function apply(Builder $query, array $campaign): Builder
    {
        [$by, $value] = $campaign;

        return $query
            ->whereExists(function ($q) use ($by, $value): void {
                $q->selectRaw('1')
                    ->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id');

                if ($by === 'id') {
                    $q->where('category_product.category_id', (int) $value);
                } else {
                    $q->join('categories', 'categories.id', '=', 'category_product.category_id')
                        ->where('categories.slug', (string) $value);
                }
            })
            ->orderBy('products.position')
            ->orderBy('products.name')
            ->orderBy('products.id');
    }
}
