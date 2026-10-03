<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → Buy these together.                  (Lane RB)
 *
 * The owner, 2 October:
 *
 *   "I want one new section called Buy these together, i need the same design
 *    section which we have on the cart page "Recommended for you" same
 *    carousel, same size. [...] here on product page, there will be empty
 *    circle at the corner of product, and by default that circle will be
 *    checked (filled green) with check (yes icon) white. and user can
 *    un-check. and whatever products are checked and user click on Buy 4
 *    items together, number 4 will count as per the user selection. [...]
 *    one from each random, or best seller or best visits. give option to
 *    choose the criteria at the backend. total 4-5 products will be shown
 *    with + inbetween icon same as in attachment."
 *
 * ── ▲ WHAT SHIPS ON (CLAUDE.md, the 30 September reversal) ─────────────────
 *
 * He asked for the section, so it is the shop's new state: migration
 * 2027_07_16_000600_buy_together_on writes `bt_on = 1` on a shop that has
 * categories and has never stored the switch. The SCHEMA default stays OFF for
 * the same reason the random light box's does — a fresh install and the test
 * suite render every product page exactly as before until something turns it
 * on, so StorefrontEnglishUnchangedTest and the query budget see nothing move.
 *
 * Four products ("Buy 4 items together" is his own example, and "the other 3")
 * — the slider goes 3 to 6. Best sellers first: it is the one rule of the
 * three he named that gives the same, sensible answer on every visit; Random
 * and Most viewed are one click away.
 *
 * ── WHERE EACH DEVICE IS SWITCHED: ONE VALUE, NEVER TWO ────────────────────
 *
 *   phones   the "Buy these together" row of Appearance → Product page →
 *            Mobile sections (ProductMobileSections key `buytogether`), which
 *            also places it in the phone page's order. Never saved there, it
 *            follows `bt_on`.
 *   laptops  the "Buy these together" row's Desktop switch on Appearance →
 *            Product page → Sections (ProductSections key `fbt`).
 *
 * The Buy these together tab draws both switches and writes them THERE, so the
 * three screens are three views of the same two values.
 */
class BuyTogetherSettings
{
    public const PREFIX = 'bt_';

    /** The rules a complementary product can be chosen by. */
    public const RULES = [
        'best' => 'Best sellers',
        'random' => 'Random — a different pick on every visit',
        'viewed' => 'Most viewed (last 30 days)',
        'newest' => 'Newest',
        'rated' => 'Top rated',
    ];

    /** key => [type, label, default, help, options] */
    public const SCHEMA = [
        'on' => ['bool', 'Show “Buy these together”', false,
            'The whole section, on every product page. Phones and laptops can each be switched below.'],

        'count' => ['range', 'How many products', 4,
            'Including the product on the page, which is always first. The button reads “Buy 4 items together” for four.',
            ['min' => 3, 'max' => 6, 'step' => 1, 'unit' => '']],

        'rule' => ['select', 'How the other products are chosen', 'best',
            'One product from each matching category (see “Category pairs” below), picked by this rule. Random changes on every page load.',
            self::RULES],

        'hide_oos' => ['bool', 'Hide sold-out products', true,
            'Off shows a sold-out match greyed out with its tick cleared, so it cannot be added. Hidden and draft products are never shown.'],

        'same_brand' => ['bool', 'Prefer the same brand', false,
            'Inside each matching category, products from the same brand as the one on the page come first.'],

        'show_total' => ['bool', 'Show the total above the button', true,
            '“Total: AED 215” — the sum of the ticked products, updated as boxes are ticked and cleared.'],

        'title' => ['text', 'Heading', '',
            'Leave empty for the standard heading, “Buy these together”.'],

        'title_ar' => ['text', 'Heading — Arabic', '',
            'Leave empty for the standard Arabic heading. Your English heading is never shown on the Arabic page.'],

        /*
         * ── THE BUNDLE DISCOUNT (Lane RE) ──────────────────────────────────
         *
         * "give option to give discount upon 5 products purchse, 4 products
         *  and 3. so the price will change upon user number of selections."
         *
         * ▲ ALL THREE SHIP AT 0, WHICH IS OFF. He asked for the OPTION and
         * named no percentage, and a discount is money: CLAUDE.md's 30
         * September reversal ships what he ASKED FOR at the value he asked
         * for, and he asked for none. Until he sets them nothing is cheaper
         * and the product page looks exactly as it did, less the layout
         * changes he did ask for.
         *
         * Whole percents, 0 to 50. Fifty is the ceiling because a bundle
         * that gives away more than half of every product in it is not a
         * setting anybody means to reach with a slider.
         *
         * Six products (when "How many products" is 6) take the FIVE tier —
         * "5 or more" — rather than a fourth slider he never asked for.
         */
        // Held to 0–50 on the way OUT as well as in: all() casts a stored row
        // through this schema, so a row written past save() — an import, a
        // hand edit — still cannot price a bundle below half.
        'tier_3' => ['range', 'Discount when 3 are bought together', 0,
            'Taken off each of the three products when the shopper ticks three and presses the button. 0 is off.',
            ['min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%']],

        'tier_4' => ['range', 'Discount when 4 are bought together', 0,
            'Taken off each of the four products. 0 is off.',
            ['min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%']],

        'tier_5' => ['range', 'Discount when 5 or more are bought together', 0,
            'Taken off each product when five are bought together — and six, when “How many products” is 6. 0 is off.',
            ['min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%']],

        /*
         * "and on top of it, the coupon can be apply. also giveo ption to
         *  include exclude the coupon apply on the buy together products."
         *
         * ON, because "the coupon can be apply" on top is what he asked for;
         * the switch is how he takes it back.
         */
        'coupons' => ['bool', 'Coupons also apply to buy-together products', true,
            'On: a coupon is taken off AFTER the bundle discount, on the already-reduced prices. Off: a coupon skips the bundled products, which keep only the bundle discount, and applies to the rest of the basket as usual.'],

        /*
         * ── THE MASTER SWITCH OVER THE TOTAL AND THE DISCOUNT (Lane RH) ─────
         *
         * The owner, 3 October:
         *
         *   "also buy together pricing and discount row, i want to hide on
         *    desktop and mobile both by default, if hide, then no any discount
         *    will be picked from the system from the buy together discount.
         *    lock that discount section is the section is hided with toggle
         *    button."
         *
         * ▲ OFF BY DEFAULT BECAUSE HE ASKED FOR OFF — the 30 September
         * reversal: what he asked for is the shop's new state. No setting row
         * is written: the absent key reads this default, so the live shop and
         * a fresh install both start OFF, and his stored tiers stay in their
         * rows untouched, ready for the day he turns it back on.
         *
         * OFF is total, not cosmetic:
         *   · the product page draws no `.bt-sumrow` (no "You're saving" pill,
         *     no "Total:" line) on any device; the button still adds the
         *     ticked products, at their own prices;
         *   · BuyTogetherPricing::percentFor() answers 0 for every size, so
         *     every surface that prices a basket (cart, drawer, checkout,
         *     order, email, invoice, the payment providers' totals) sees no
         *     bundle at all, and a coupon sees ordinary lines;
         *   · save() will not change a tier or the coupons switch while it is
         *     off — the admin draws them locked, and the server holds the lock
         *     too (LOCKED below).
         */
        'discount_on' => ['bool', 'Show the total and buy-together discount', false,
            'Off: no total or “You’re saving” line on the product page, and no buy-together discount anywhere — cart, checkout or order. The button still adds the ticked products at their normal prices. Your percentages below are kept for when you turn it back on.'],

        // Under the master switch: only where the row is DRAWN. The basket
        // cannot know which device a product was added from, so hiding the
        // row on one device never stops the discount there.
        'row_phone' => ['bool', 'Show on phones', true,
            'The total and “You’re saving” line on phones and tablets (narrower than 1024px).'],

        'row_laptop' => ['bool', 'Show on laptops', true,
            'The discount still applies at checkout on a device where the row is hidden; switch the whole thing off above to stop the discount.'],
    ];

    /**
     * What the master switch locks: the money. While `discount_on` is off,
     * save() keeps the stored value of each of these whatever a request
     * posts, so nothing can be priced by going round the locked controls.
     */
    public const LOCKED = ['tier_3', 'tier_4', 'tier_5', 'coupons'];

    public const TABS = [
        'together' => ['Buy these together',
            'The bundle box under the buy column: the product on the page plus one match from each category that goes with it, a tick on each, and one pink button that adds every ticked product.',
            ['on', 'count', 'rule', 'hide_oos', 'same_brand', 'show_total', 'title', 'title_ar', 'tier_3', 'tier_4', 'tier_5', 'coupons', 'discount_on', 'row_phone', 'row_laptop']],
    ];

    public const POLICY = [
        'max' => 60,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> every value, saved or shipped */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $out = [];

        foreach (self::fields() as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : $this->cast($key, $saved);
        }

        // A select answers one of its own keys or its default (rule 5's second lock).
        if (! array_key_exists((string) $out['rule'], self::RULES)) {
            $out['rule'] = self::SCHEMA['rule'][2];
        }

        $out['count'] = max(3, min(6, (int) $out['count']));

        return $this->memo = $out;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $def) => $def[2], self::SCHEMA);
    }

    /**
     * Is the buy-together total shown and its discount live? (Lane RH)
     *
     * The ONE question every price and every drawing of the row asks. Off by
     * default — see `discount_on` in SCHEMA.
     */
    public function discountOn(): bool
    {
        return ! empty($this->all()['discount_on']);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        // The master switch as it will stand after this save: the posted
        // value when there is one, else the stored one.
        $on = array_key_exists('discount_on', $values)
            ? (bool) $this->cast('discount_on', $values['discount_on'])
            : $this->discountOn();

        foreach ($values as $key => $value) {
            // Locked while off: the stored tier is kept, not overwritten.
            if (! $on && in_array($key, self::LOCKED, true)) {
                continue;
            }

            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }

        $this->memo = null;
    }

    /** The admin payload: ModuleSchema::tabs(), like the other Product page halves. */
    public function tabs(): array
    {
        return ModuleSchema::tabs(self::SCHEMA, self::TABS, $this->all(), self::POLICY);
    }

    /** The heading for the language this page is in. */
    public static function heading(array $c): string
    {
        $ar = ! Locale::isDefault() && Locale::current() === 'ar';
        $title = trim((string) ($ar ? ($c['title_ar'] ?? '') : ($c['title'] ?? '')));

        return $title !== '' ? $title : (string) __('store.buy_together.heading');
    }

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }
}
