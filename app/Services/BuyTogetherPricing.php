<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\WholeDirhams;

/**
 * What a "Buy these together" group takes off a basket.             (Lane RE)
 *
 * The owner, 2 October:
 *
 *   "give option to give discount upon 5 products purchse, 4 products and 3.
 *    so the price will change upon user number of selections. and if any
 *    product removed from the cart, the other products prices will become
 *    normal without buy together discount."
 *
 * and, the same day, on coupons:
 *
 *   "and on top of it, the coupon can be apply. also giveo ption to include
 *    exclude the coupon apply on the buy together products."
 *
 * ── WHERE A GROUP COMES FROM ────────────────────────────────────────────────
 *
 * ONLY from Store\CartController::addTogether(), which stamps every line it
 * added in one press with a handle it minted itself (`cart_items.bt_group`)
 * and the number of lines it put in (`bt_size`). Nothing a browser sends can
 * name a group or a percentage: the request carries product ids and nothing
 * else, and the handle is 32 random characters the server chose.
 *
 * ── EVERY PRICING PASS RE-CHECKS THE GROUP ──────────────────────────────────
 *
 * A group earns its tier on THIS pass only if, now:
 *
 *   1. it is COMPLETE — exactly `bt_size` lines still carry its handle, and
 *      that is at least three. A line removed (or set to 0, which is the same
 *      thing) is a group dissolved; CartService also clears the handle on the
 *      survivors so it cannot be completed again by accident;
 *   2. every member is still VISIBLE (published, not hidden, not scheduled)
 *      and IN STOCK — the option, for a line that holds one;
 *   3. the section is on, "Show the total and buy-together discount" is on
 *      (Lane RH; off by default), and the tier for its size, READ FROM
 *      SETTINGS NOW, is above 0. A tier changed in the admin — or the master
 *      switch turned off — reprices every open basket.
 *
 * Fail any one and the group's lines are ordinary lines at their own prices,
 * on every surface, with a coupon free to treat them like any other line.
 *
 * ── THE QUANTITY RULE: ONE BUNDLE PER COMPLETE SET ──────────────────────────
 *
 * A bundle is one of each. So the discount is taken off as many units of each
 * line as there are COMPLETE SETS — the smallest quantity in the group:
 *
 *     A ×1, B ×1, C ×1   → one set:  one unit of each at the bundle price
 *     A ×2, B ×2, C ×2   → two sets: every unit at the bundle price
 *     A ×3, B ×1, C ×1   → one set:  one A, B and C at the bundle price,
 *                                    the other two As at A's own price
 *
 * Raising one line therefore never deepens the discount on its own, and the
 * shopper who buys the whole bundle twice gets it twice. Reducing a line to 0
 * is removing it: the group dissolves.
 *
 * ── THE ARITHMETIC ──────────────────────────────────────────────────────────
 *
 * Per unit, on the price the basket charges for that unit (`unit_price`, the
 * server's own snapshot): the reduced price is `unit × (100 − p) / 100`,
 * rounded half up to the fil, then DOWN to the whole dirham —
 * WholeDirhams::toward(), the direction BundleService takes for the same
 * reason: a discounted price rounds toward the shopper, so "10% off" is a
 * floor and never a ceiling. fbt.js makes the identical sum in integers for the
 * live total on the product page, so the page and the basket agree to the fil.
 *
 * ── WHAT IT COSTS ───────────────────────────────────────────────────────────
 *
 * NOTHING AT ALL for a basket with no group in it — no query, which is every
 * basket in this shop until the button is pressed with a tier set. With a
 * group, ONE query for the members' products and, only when a member is an
 * option, one for the options. Never one per line.
 */
class BuyTogetherPricing
{
    public const MIN_GROUP = 3;

    public const MAX_GROUP = 6;

    /** The answer for a basket with nothing grouped. */
    public const NONE = ['total' => 0, 'lines' => [], 'groups' => []];

    public function __construct(private BuyTogetherSettings $settings) {}

    /**
     * The percentage a group of this many products earns, from settings.
     *
     * 3 → tier_3, 4 → tier_4, 5 and 6 → tier_5 ("5 or more"). Anything under
     * three earns nothing: "Buy 2 items together" is two products, not a
     * bundle he priced.
     */
    public function percentFor(int $size): int
    {
        if ($size < self::MIN_GROUP || $size > self::MAX_GROUP) {
            return 0;
        }

        $c = $this->settings->all();

        // (Lane RH) The master switch: off, and no group is worth anything on
        // any surface, whatever the tiers hold. Every price below — forCart(),
        // tiers(), anyTier(), and so the cart, drawer, checkout, order, email,
        // invoice and the payment providers' totals — comes through here.
        if (empty($c['on']) || empty($c['discount_on'])) {
            return 0;
        }

        return max(0, min(50, (int) ($c['tier_'.min(5, $size)] ?? 0)));
    }

    /**
     * Every tier, keyed by group size, for the product page's live total.
     *
     * @return array<int, int>  3..6 => percent
     */
    public function tiers(): array
    {
        $out = [];

        for ($n = self::MIN_GROUP; $n <= self::MAX_GROUP; $n++) {
            $out[$n] = $this->percentFor($n);
        }

        return $out;
    }

    /** Is any tier above 0? Nothing about a basket changes until one is. */
    public function anyTier(): bool
    {
        return max($this->tiers()) > 0;
    }

    /** Do coupons also discount bundled units (Include) or skip them (Exclude)? */
    public function couponsInclude(): bool
    {
        $c = $this->settings->all();

        // (Lane RH) No discount, no bundle for a coupon to skip: with the
        // master switch off a coupon treats every line as an ordinary line.
        return empty($c['discount_on']) || (bool) ($c['coupons'] ?? true);
    }

    /**
     * What one unit at $unit fils is reduced BY at $percent.
     *
     * Integer arithmetic only — see the class header. 0 at 0%, and never more
     * than the unit itself.
     */
    public static function unitOff(int $unit, int $percent): int
    {
        $percent = max(0, min(50, $percent));

        if ($percent === 0 || $unit <= 0) {
            return 0;
        }

        $exact = intdiv($unit * (100 - $percent) + 50, 100);
        $reduced = WholeDirhams::toward($exact);

        return max(0, min($unit, $unit - $reduced));
    }

    /**
     * Price every group in this basket.
     *
     * Reads `$cart->items` as the caller loaded them, with their `product`
     * and `variant` relations where present. Writes nothing.
     *
     * @return array{
     *     total: int,
     *     lines: array<int, array{group: string, size: int, percent: int, sets: int, unit_off: int, off: int}>,
     *     groups: list<array{group: string, size: int, percent: int, off: int}>
     * }
     */
    public function forCart(Cart $cart): array
    {
        $items = $cart->relationLoaded('items') ? $cart->items : $cart->items()->get();

        // Group what carries a handle. No handle anywhere: no query, no work.
        $groups = [];

        foreach ($items as $item) {
            $g = (string) ($item->bt_group ?? '');

            if ($g !== '') {
                $groups[$g][] = $item;
            }
        }

        if ($groups === []) {
            return self::NONE;
        }

        // 1. complete, of a size that has a tier — decided before any query.
        $candidates = [];

        foreach ($groups as $g => $lines) {
            $size = (int) ($lines[0]->bt_size ?? 0);
            $sameSize = collect($lines)->every(fn ($l) => (int) $l->bt_size === $size);

            if (! $sameSize || count($lines) !== $size) {
                continue;
            }

            $percent = $this->percentFor($size);

            if ($percent <= 0) {
                continue;
            }

            $candidates[$g] = ['lines' => $lines, 'size' => $size, 'percent' => $percent];
        }

        if ($candidates === []) {
            return self::NONE;
        }

        // 2. visible and in stock, now — one query for the products and one
        //    for the options, whatever the number of lines.
        $productIds = [];
        $variantIds = [];

        foreach ($candidates as $c) {
            foreach ($c['lines'] as $line) {
                $productIds[] = (int) $line->product_id;

                if ($line->product_variant_id !== null) {
                    $variantIds[] = (int) $line->product_variant_id;
                }
            }
        }

        $products = Product::query()->visible()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->get(['id', 'type', 'stock_status'])
            ->keyBy(fn ($p) => (int) $p->id);

        $variants = $variantIds === [] ? collect() : ProductVariant::query()
            ->whereIn('id', array_values(array_unique($variantIds)))
            ->get(['id', 'product_id', 'stock_status'])
            ->keyBy(fn ($v) => (int) $v->id);

        $out = self::NONE;

        foreach ($candidates as $g => $c) {
            $ok = true;

            foreach ($c['lines'] as $line) {
                $product = $products->get((int) $line->product_id);

                if ($product === null) {
                    $ok = false;
                    break;
                }

                if ($line->product_variant_id !== null) {
                    $variant = $variants->get((int) $line->product_variant_id);

                    if ($variant === null
                        || (int) $variant->product_id !== (int) $product->id
                        || $variant->stock_status !== 'instock') {
                        $ok = false;
                        break;
                    }
                } elseif ($product->type === 'variable' || $product->stock_status !== 'instock') {
                    $ok = false;
                    break;
                }
            }

            if (! $ok) {
                continue;
            }

            // 3. one bundle per complete set.
            $sets = (int) min(array_map(fn ($l) => max(0, (int) $l->quantity), $c['lines']));

            if ($sets <= 0) {
                continue;
            }

            $groupOff = 0;

            foreach ($c['lines'] as $line) {
                $unitOff = self::unitOff((int) $line->unit_price, $c['percent']);
                $off = $unitOff * $sets;

                $out['lines'][(int) $line->id] = [
                    'group' => (string) $g,
                    'size' => $c['size'],
                    'percent' => $c['percent'],
                    'sets' => $sets,
                    'unit_off' => $unitOff,
                    'off' => $off,
                ];

                $groupOff += $off;
            }

            $out['groups'][] = ['group' => (string) $g, 'size' => $c['size'], 'percent' => $c['percent'], 'off' => $groupOff];
            $out['total'] += $groupOff;
        }

        return $out;
    }

    /**
     * The lines a coupon sees, after the bundle.
     *
     * A grouped line is split in two: the units inside a complete set, at the
     * bundle price (Include) or left out altogether (Exclude), and any units
     * beyond the sets at the line's own price, which are ordinary units either
     * way. A line in no group — or in a group that did not earn a tier on this
     * pass — is passed through unchanged.
     *
     * @param  iterable<\App\Models\CartItem>  $items  already filtered by the coupon's own rules
     * @param  array{lines: array<int, array<string, mixed>>}  $quote  forCart()'s answer
     * @return list<array{unit_price: int, quantity: int, id: int}>
     */
    public static function couponLines(iterable $items, array $quote, bool $include): array
    {
        $out = [];

        foreach ($items as $item) {
            $id = (int) $item->getKey();
            $unit = (int) $item->unit_price;
            $qty = (int) $item->quantity;
            $b = $quote['lines'][$id] ?? null;

            if ($b === null) {
                $out[] = ['unit_price' => $unit, 'quantity' => $qty, 'id' => $id];

                continue;
            }

            $sets = min($qty, (int) $b['sets']);

            if ($include && $sets > 0) {
                $out[] = ['unit_price' => $unit - (int) $b['unit_off'], 'quantity' => $sets, 'id' => $id];
            }

            if ($qty - $sets > 0) {
                $out[] = ['unit_price' => $unit, 'quantity' => $qty - $sets, 'id' => $id];
            }
        }

        return $out;
    }
}
