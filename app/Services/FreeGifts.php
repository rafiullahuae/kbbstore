<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\Locale;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

/**
 * Free gifts -- Marketing -> Free gifts (Lane FG).
 *
 * The owner, October 2026: spend AED 100 and get 2 masks, AED 300 and get 3
 * masks and a mini, up to AED 2,000+ and 12 masks, 5 minis and 3 full-size
 * products. One band at a time, never cumulative.
 *
 * ── THE SERVER IS THE ONLY AUTHORITY ────────────────────────────────────────
 *
 * Gifts are NOT cart lines. Nothing a browser posts can add, remove or resize
 * one: every surface (the cart panel, the cart page, the checkout summary)
 * asks forTotals() with the totals CartService::totals() has just computed,
 * and Store\CheckoutController::place() asks the SAME method again with the
 * order's own totals and writes what it answers. A coupon added or removed, a
 * quantity changed, a line removed -- each of those already re-renders the
 * surfaces from fresh totals, so the gifts follow without any code of their own.
 *
 * ── WHAT COUNTS ─────────────────────────────────────────────────────────────
 *
 * basis(): the products' subtotal after the buy-together discount and the
 * coupon ("after_discounts", the shipped default), or before them. Never
 * delivery, never gift wrapping (both are added after totals() returns), never
 * the gifts themselves (they are not lines).
 *
 * A band "100 - 299" covers AED 100.00 up to AED 299.99: a band's maximum is
 * inclusive of its whole last dirham, so a coupon that leaves AED 299.50 does
 * not fall into a gap between two bands the owner wrote as touching.
 *
 * ── COST ───────────────────────────────────────────────────────────────────
 *
 * The configuration is two autoloaded settings rows, read off the settings map
 * SettingsRequestMemo already holds: NO query to know whether there is a gift.
 * Off, or a basket under the first band: nothing at all. A basket in a band:
 * ONE query for its products (their brands on a join), ONCE per request
 * however many surfaces draw the box (memoised on the request), and that one
 * however many gifts the band holds.
 */
final class FreeGifts
{
    /** The owner's settings: switch, display, texts, homepage section. */
    public const KEY = 'free_gifts';

    /** The bands and their gifts, compiled from the two tables on every save. */
    public const COMPILED = 'free_gifts_compiled';

    public const BASES = ['after_discounts', 'before_discounts'];

    public const COUNTRIES = ['all', 'AE'];

    public const MAX_QTY = 50;

    /** Every text the shopper reads, in both languages. Settings, printed escaped. */
    public const TEXTS = [
        'title' => ['Free gifts for you', 'هدايا مجانية لكِ'],
        'free' => ['FREE', 'مجاناً'],
        'view_all' => ['View all', 'عرض الكل'],
        'hide' => ['Hide', 'إخفاء'],
        'line' => ['Free gifts (:count)', 'هدايا مجانية (:count)'],
        'tag' => ['Free gift', 'هدية مجانية'],
        // " · N gifts" after the band's wording. Arabic counts four ways
        // (هدية واحدة / هديتان / 3-10 هدايا / 11+ هدية), English two.
        'count_one' => ['1 gift', 'هدية واحدة'],
        'count_two' => [':count gifts', 'هديتان'],
        'count_few' => [':count gifts', ':count هدايا'],
        'count_many' => [':count gifts', ':count هدية'],
    ];

    /** The homepage section's data. No storefront section yet: the owner is choosing its design. */
    public const HOME = [
        'on' => false,
        'title_en' => 'Free gifts with every order', 'title_ar' => 'هدايا مجانية مع كل طلب',
        'sub_en' => 'The more you shop, the more you get.', 'sub_ar' => 'كلما تسوّقتِ أكثر، حصلتِ على المزيد.',
        'btn_en' => 'Shop now', 'btn_ar' => 'تسوّقي الآن',
    ];

    public const PRODUCT_COLUMNS = [
        'products.id', 'products.name', 'products.slug', 'products.sku', 'products.brand_id', 'products.image', 'products.type',
        'products.status', 'products.stock_status', 'products.manage_stock', 'products.stock',
        'products.price', 'products.sale_price', 'products.sale_starts_at', 'products.sale_ends_at',
        'products.gift_only', 'products.is_visible',
    ];

    private ?array $config = null;

    public function __construct(private SettingsService $settings) {}

    /** The owner's settings, every key present, every select one of its own options. */
    public function config(): array
    {
        return $this->config ??= $this->readConfig();
    }

    private function readConfig(): array
    {
        $saved = $this->settings->get(self::KEY);
        $saved = is_array($saved) ? $saved : [];

        $texts = [];
        foreach (self::TEXTS as $key => [$en, $ar]) {
            $texts[$key . '_en'] = self::str($saved['texts'][$key . '_en'] ?? null, $en);
            $texts[$key . '_ar'] = self::str($saved['texts'][$key . '_ar'] ?? null, $ar);
        }

        $home = [];
        foreach (self::HOME as $key => $default) {
            $home[$key] = is_bool($default)
                ? (bool) ($saved['home'][$key] ?? $default)
                : self::str($saved['home'][$key] ?? null, $default);
        }

        return [
            'on' => (bool) ($saved['on'] ?? false),
            'show_value' => (bool) ($saved['show_value'] ?? false),
            'countries' => in_array($saved['countries'] ?? null, self::COUNTRIES, true) ? $saved['countries'] : 'all',
            'basis' => in_array($saved['basis'] ?? null, self::BASES, true) ? $saved['basis'] : 'after_discounts',
            'texts' => $texts,
            'home' => $home,
        ];
    }

    public function on(): bool
    {
        $saved = $this->settings->get(self::KEY);

        return is_array($saved) && ! empty($saved['on']);
    }

    /** One text in the language being drawn (the order's, inside OrderLocale::render()). */
    public function text(string $key, array $replace = []): string
    {
        $config = $this->config();
        $value = $config['texts'][$key . (Locale::isDefault() ? '_en' : '_ar')] ?? '';

        foreach ($replace as $name => $with) {
            $value = str_replace(':' . $name, (string) $with, $value);
        }

        return $value;
    }

    /** The amount a basket is measured on, in fils. */
    public function basis(array $totals): int
    {
        $subtotal = (int) ($totals['subtotal'] ?? 0);

        if ($this->config()['basis'] === 'before_discounts') {
            return max(0, $subtotal);
        }

        return max(0, $subtotal - (int) ($totals['bundle_discount'] ?? 0) - (int) ($totals['discount'] ?? 0));
    }

    public function countryAllowed(?string $country): bool
    {
        $rule = $this->config()['countries'];

        return $rule === 'all' || strtoupper((string) $country) === $rule;
    }

    /**
     * The bands as compiled, enabled only, lowest first.
     *
     * @return list<array{id:int,min:int,max:?int,en:string,ar:string,starts:?string,ends:?string,gifts:list<array{0:int,1:int}>}>
     */
    public function ranges(): array
    {
        $compiled = $this->settings->get(self::COMPILED);

        if (! is_array($compiled) || ! isset($compiled['ranges']) || ! is_array($compiled['ranges'])) {
            $compiled = $this->compile();
        }

        return $compiled['ranges'];
    }

    /** The band an amount falls in today, or null. */
    public function rangeFor(int $fils): ?array
    {
        $today = StoreTime::today()->format('Y-m-d');

        foreach ($this->ranges() as $range) {
            if (($range['starts'] ?? null) !== null && $today < $range['starts']) {
                continue;
            }

            if (($range['ends'] ?? null) !== null && $today > $range['ends']) {
                continue;
            }

            if (self::covers($range['min'], $range['max'], $fils)) {
                return $range;
            }
        }

        return null;
    }

    /** min <= amount, and amount below the dirham after max (null = and above). */
    public static function covers(int $min, ?int $max, int $fils): bool
    {
        return $fils >= $min && ($max === null || $fils < $max + 100);
    }

    /** Do two bands share any amount? */
    public static function overlaps(int $aMin, ?int $aMax, int $bMin, ?int $bMax): bool
    {
        $aEnd = $aMax === null ? PHP_INT_MAX : $aMax + 100;
        $bEnd = $bMax === null ? PHP_INT_MAX : $bMax + 100;

        return $aMin < $bEnd && $bMin < $aEnd;
    }

    /**
     * The gift lines for a basket's totals.
     *
     * A gift whose product is gone, unpublished, a variable product, or sold
     * out is left out without a word; one with less stock than its quantity
     * gives what there is.
     *
     * @return list<array{product:Product,product_id:int,quantity:int,value:int,range_id:int,range_label:string}>
     */
    public function forTotals(array $totals, ?string $country = null): array
    {
        if (! $this->on() || (int) ($totals['item_count'] ?? 1) < 1) {
            return [];
        }

        $range = $this->rangeFor($this->basis($totals));

        if ($range === null || $range['gifts'] === []) {
            return [];
        }

        $country ??= $this->shopperCountry();

        if (! $this->countryAllowed($country)) {
            return [];
        }

        return $this->linesFor($range);
    }

    /** @return list<array{product:Product,product_id:int,quantity:int,value:int,range_id:int,range_label:string}> */
    public function linesFor(array $range): array
    {
        $products = $this->products(array_map(static fn (array $g) => (int) $g[0], $range['gifts']));
        $label = self::rangeLabel($range);
        $out = [];

        foreach ($range['gifts'] as [$productId, $quantity]) {
            $product = $products[(int) $productId] ?? null;

            if ($product === null || $product->status !== 'publish' || $product->type === 'variable'
                || $product->stock_status !== 'instock') {
                continue;
            }

            $quantity = max(1, min(self::MAX_QTY, (int) $quantity));

            if ($product->manage_stock) {
                $quantity = min($quantity, max(0, (int) $product->stock));
            }

            if ($quantity < 1) {
                continue;
            }

            $out[] = [
                'product' => $product,
                'product_id' => (int) $product->id,
                'quantity' => $quantity,
                'value' => max(0, (int) $product->effectivePrice()) * $quantity,
                'range_id' => (int) $range['id'],
                'range_label' => $label,
            ];
        }

        return $out;
    }

    /**
     * What the box draws for each line: name and brand in the language being
     * drawn, the picture, the quantity and (only if Show gift value is on) the
     * value. Plain strings; the view escapes every one.
     *
     * @return list<array{name:string,brand:string,image:string,quantity:int,value:int}>
     */
    public static function rows(array $lines): array
    {
        return array_map(static function (array $l): array {
            $p = $l['product'];

            return [
                'name' => (string) ($p->t('name') ?: $p->name),
                'brand' => (string) ($p->brand?->t('name') ?? ''),
                'image' => (string) ($p->image ?? ''),
                'quantity' => (int) $l['quantity'],
                'value' => (int) $l['value'],
            ];
        }, $lines);
    }

    /**
     * The same rows from an order's own gift lines -- the snapshot, never the
     * live band. NO query for an order without gifts (`free_gift_count` 0,
     * which is every order before this package); one for an order with them.
     *
     * @return list<array{name:string,brand:string,image:string,quantity:int,value:int}>
     */
    public static function orderRows(\App\Models\Order $order): array
    {
        if ((int) ($order->free_gift_count ?? 0) < 1) {
            return [];
        }

        $order->loadMissing('gifts.product:id,image');

        return $order->gifts->map(static fn ($i) => [
            'name' => (string) ((trim((string) ($i->name_localised ?? '')) !== '' && ! Locale::isDefault()) ? $i->name_localised : $i->name),
            'brand' => (string) ($i->brand ?? ''),
            'image' => (string) ($i->product?->image ?? ''),
            'quantity' => (int) $i->quantity,
            'value' => 0,
        ])->values()->all();
    }

    /** "AED 100 – 299" / "AED 2,000+", the operator's record of a band. */
    public static function rangeLabel(array $range): string
    {
        $min = number_format(intdiv((int) $range['min'], 100));

        return $range['max'] === null
            ? 'AED ' . $min . '+'
            : 'AED ' . $min . ' – ' . number_format(intdiv((int) $range['max'], 100));
    }

    /** Gift units in a list of lines. */
    public static function units(array $lines): int
    {
        return array_sum(array_map(static fn (array $l) => (int) $l['quantity'], $lines));
    }

    /**
     * The products for a band, ONCE per request: the drawer, the cart page and
     * both copies of the checkout's order block ask for the same ids.
     *
     * @param  list<int>  $ids
     * @return array<int, Product>
     */
    private function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        $key = 'kbb.free_gifts.products.' . implode(',', $ids);
        $request = app()->bound('request') ? request() : null;

        if ($request !== null && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        // ONE statement: the brand comes in on a join and is set as the
        // relation by hand, so the box costs a single query whatever the band
        // holds (FreeGiftsTest pins 3 gifts and 40 at the same count).
        $rows = Product::query()
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('products.id', $ids)
            ->get(array_merge(self::PRODUCT_COLUMNS, ['brands.name as fg_brand_name', 'brands.slug as fg_brand_slug']))
            ->each(static function (Product $p): void {
                $brand = null;

                if ($p->brand_id !== null && $p->getAttribute('fg_brand_name') !== null) {
                    $brand = (new \App\Models\Brand)->forceFill(['id' => (int) $p->brand_id, 'name' => $p->getAttribute('fg_brand_name'), 'slug' => $p->getAttribute('fg_brand_slug')]);
                    $brand->exists = true;
                    $brand->syncOriginal();
                }

                $p->offsetUnset('fg_brand_name');
                $p->offsetUnset('fg_brand_slug');
                $p->syncOriginal();
                $p->setRelation('brand', $brand);
            })
            ->keyBy('id')
            ->all();

        $request?->attributes->set($key, $rows);

        return $rows;
    }

    private function shopperCountry(): string
    {
        if ($this->config()['countries'] === 'all') {
            return '';
        }

        try {
            return \App\Support\ShopperCountry::for(request())->code;
        } catch (\Throwable) {
            return (string) $this->settings->get('store_country', 'AE');
        }
    }

    /**
     * Rebuild the compiled bands from the two tables and store them: two
     * queries, on a save in the admin (or once, if the row was ever lost).
     */
    public function compile(): array
    {
        $ranges = DB::table('free_gift_ranges')->where('enabled', true)->orderBy('min_fils')->orderBy('sort')->orderBy('id')->get();
        $items = $ranges->isEmpty() ? collect() : DB::table('free_gift_range_items')
            ->whereIn('range_id', $ranges->pluck('id')->all())
            ->orderBy('sort')->orderBy('id')
            ->get(['range_id', 'product_id', 'quantity'])
            ->groupBy('range_id');

        $out = [];

        foreach ($ranges as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'min' => (int) $r->min_fils,
                'max' => $r->max_fils === null ? null : (int) $r->max_fils,
                'en' => (string) $r->label_en,
                'ar' => (string) $r->label_ar,
                'starts' => $r->starts_at ? substr((string) $r->starts_at, 0, 10) : null,
                'ends' => $r->ends_at ? substr((string) $r->ends_at, 0, 10) : null,
                'gifts' => array_values(array_map(
                    static fn ($i) => [(int) $i->product_id, max(1, min(self::MAX_QTY, (int) $i->quantity))],
                    ($items[$r->id] ?? collect())->all(),
                )),
            ];
        }

        $compiled = ['v' => 1, 'ranges' => $out];
        $this->settings->set(self::COMPILED, $compiled);

        return $compiled;
    }

    /** Save the owner's settings (already validated by the admin controller). */
    public function saveConfig(array $config): void
    {
        $this->settings->set(self::KEY, $config);
        $this->config = null;
    }

    /**
     * What stands between the owner and switching this on.
     *
     * `block` items refuse the switch; `warn` items do not (a sold-out gift
     * is skipped on the shop, which is the documented behaviour).
     *
     * @return list<array{level:string,text:string}>
     */
    public function checklist(): array
    {
        $ranges = DB::table('free_gift_ranges')->orderBy('min_fils')->get();
        $enabled = $ranges->where('enabled', true);
        $items = DB::table('free_gift_range_items')->get(['range_id', 'product_id', 'quantity'])->groupBy('range_id');
        $productIds = $items->flatten(1)->pluck('product_id')->unique()->values()->all();
        $products = $productIds === [] ? collect() : Product::withTrashed()->whereIn('id', $productIds)
            ->get(['id', 'name', 'status', 'type', 'stock_status', 'manage_stock', 'stock', 'deleted_at'])->keyBy('id');

        $out = [];

        if ($enabled->isEmpty()) {
            $out[] = ['level' => 'block', 'text' => 'Add at least one spend range and switch it on.'];
        }

        foreach ($enabled as $r) {
            $label = self::rangeLabel(['min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils]);
            $gifts = $items[$r->id] ?? collect();

            if ($gifts->isEmpty()) {
                $out[] = ['level' => 'block', 'text' => $label . ' ("' . $r->label_en . '"): no gift products picked yet.'];

                continue;
            }

            $names = [];

            foreach ($gifts as $g) {
                $p = $products[$g->product_id] ?? null;

                if ($p === null || $p->deleted_at !== null || $p->status !== 'publish') {
                    $out[] = ['level' => 'block', 'text' => $label . ': a picked product is deleted or not published.'];
                } elseif ($p->type === 'variable') {
                    $out[] = ['level' => 'block', 'text' => $label . ': "' . $p->name . '" has options (shades/sizes); pick a simple product.'];
                } elseif ($p->stock_status !== 'instock' || ($p->manage_stock && (int) $p->stock < 1)) {
                    $out[] = ['level' => 'warn', 'text' => $label . ': "' . $p->name . '" is out of stock, so it is skipped until restocked.'];
                } elseif ($p->manage_stock && (int) $p->stock < (int) $g->quantity) {
                    $out[] = ['level' => 'warn', 'text' => $label . ': only ' . (int) $p->stock . ' of "' . $p->name . '" left for ' . (int) $g->quantity . ' per order.'];
                }

                $names[] = mb_strtolower((string) ($p->name ?? ''));
            }

            if (str_contains(mb_strtolower((string) $r->label_en), 'mask')
                && ! collect($names)->contains(static fn (string $n) => str_contains($n, 'mask'))) {
                $out[] = ['level' => 'warn', 'text' => $label . ' says "' . $r->label_en . '" but no mask product is picked. Pick the sheet mask product.'];
            }
        }

        return $out;
    }

    public function blocked(): bool
    {
        foreach ($this->checklist() as $item) {
            if ($item['level'] === 'block') {
                return true;
            }
        }

        return false;
    }

    /**
     * The units for the checkout's "Free gifts (N)" line: counted as if the
     * destination qualified, because the line is hidden by :has() whenever the
     * box above it is absent (a UAE-only gift the country change removed).
     */
    public function lineUnits(array $totals): int
    {
        return self::units($this->forTotals($totals, 'AE'));
    }

    /** "4 gifts" / "4 هدايا", in the language being drawn. */
    public function count(int $n): string
    {
        $form = $n === 1 ? 'count_one' : ($n === 2 ? 'count_two' : ($n <= 10 ? 'count_few' : 'count_many'));

        return $this->text($form, ['count' => $n]);
    }

    /**
     * Everything the box partial needs for a basket: its rows and the band's
     * wording in the language being drawn ("3 Masks + 1 Mini Product").
     *
     * @return array{kbbFgRows: list<array>, kbbFgWords: string}
     */
    public function box(array $totals, ?string $country = null): array
    {
        $lines = $this->forTotals($totals, $country);

        if ($lines === []) {
            return ['kbbFgRows' => [], 'kbbFgWords' => ''];
        }

        $words = '';

        foreach ($this->ranges() as $range) {
            if ((int) $range['id'] === (int) $lines[0]['range_id']) {
                $words = Locale::isDefault() || trim((string) $range['ar']) === '' ? (string) $range['en'] : (string) $range['ar'];
            }
        }

        return ['kbbFgRows' => self::rows($lines), 'kbbFgWords' => $words];
    }

    private static function str(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 160) : $default;
    }
}
