<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * Product Labels — ported from the `kbb_product_label` filter in
 * KBB Modules v2.39.0 (lines 513–539).
 *
 * The plugin's behaviour is carried across exactly, including the parts that
 * are easy to get wrong:
 *
 *  - **One badge, never two.** The filter returns on the first match, so the
 *    order below is the precedence: sold out, then sale, then new, then
 *    bestseller. A sold-out product on sale shows "Sold out", not both.
 *  - **On but nothing matched means no badge**, not a fallback to the theme's
 *    own. Turning the module on takes over badges completely.
 *  - `{off}` in the sale text is replaced with the rounded discount.
 *
 * Off by default, as the plugin ships it — the theme's built-in badges show
 * until it is turned on.
 */
class ProductLabels
{
    /** Same keys and defaults as the plugin's save handler (lines 453–467). */
    public const SCHEMA = [
        'oos_on'     => ['bool',   'Sold-out badge', true, ''],
        'oos_text'   => ['text',   'Sold-out text', 'Sold out', ''],
        'oos_color'  => ['colour', 'Sold-out colour', '#8C828A', ''],

        'sale_on'    => ['bool',   'Sale badge', true, ''],
        'sale_text'  => ['text',   'Sale text', '-{off}% OFF', 'Use {off} where the discount percentage should go.'],
        'sale_color' => ['colour', 'Sale colour', '#E23A4E', ''],

        'new_on'     => ['bool',   'New badge', true, ''],
        'new_days'   => ['range',  'Counts as new for', 30, 'Days after the product was added.', ['min' => 1, 'max' => 180, 'step' => 1, 'unit' => ' days']],
        'new_text'   => ['text',   'New text', 'New', ''],
        'new_color'  => ['colour', 'New colour', '#C13E63', ''],

        'feat_on'    => ['bool',   'Bestseller badge', true, ''],
        'feat_text'  => ['text',   'Bestseller text', 'Bestseller', ''],
        'feat_color' => ['colour', 'Bestseller colour', '#1B9E77', ''],
    ];

    public const TABS = [
        'badges' => ['Badges', 'One badge shows at a time, in this order: sold out, sale, new, bestseller.',
                     ['oos_on', 'oos_text', 'oos_color', 'sale_on', 'sale_text', 'sale_color',
                      'new_on', 'new_days', 'new_text', 'new_color',
                      'feat_on', 'feat_text', 'feat_color']],
    ];

    private ?array $cache = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            // module_settings is the table the plugin's per-module options map to.
            $saved = $this->settings->moduleSetting('product_labels', $key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $this->cache = $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->setModuleSetting('product_labels', $key, $this->cast($key, $value));
            }
        }

        $this->cache = null;
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            // isValidHex() accepts a hex with OR without the leading '#', so a
            // stored "e23a4e" came back out as "E23A4E" and went into
            // style="background:E23A4E" — not a colour, so the badge drew with
            // no background and its white text vanished. The '#' is put back on
            // rather than trusting every caller to have sent one; the colour
            // picker on the screen always does, a POST to the endpoint need not.
            'colour' => \App\Support\Color::isValidHex((string) $value)
                ? '#' . strtoupper(ltrim((string) $value, '#'))
                : $def[2],
            default => mb_substr(trim((string) $value), 0, 40),
        };
    }

    /**
     * The badge for one product, or null.
     *
     * Returns text and colour rather than markup so the card and the gallery can
     * each place it in their own element — the plugin returned a `<span class="lbl">`
     * because WordPress gave it one insertion point; here there are two.
     *
     * @return array{text: string, colour: string}|null
     */
    public function for(Product $product): ?array
    {
        if (! $this->settings->moduleEnabled('product_labels', false)) {
            return null;
        }

        $c = $this->all();

        // The card's own test, so a badge and a disabled Add button never disagree.
        if ($c['oos_on'] && $product->stock_status !== 'instock') {
            return ['text' => $c['oos_text'], 'colour' => $c['oos_color']];
        }

        /*
         * ONE SOURCE OF TRUTH FOR "IS THIS ON SALE, AND BY HOW MUCH".
         *
         * This branch used to do its own arithmetic — `(float) $product->price`
         * against `effectivePrice()`, guarded by `$sale > 0` — beside
         * Product::isOnSale()/discountPercent(), which the price block on the
         * very same card already uses. Two copies of one rule, and they did not
         * agree:
         *
         *  - A MARKDOWN THAT ROUNDS TO NOTHING PRINTED "-0% OFF". AED 100.00
         *    down to AED 99.80 is a real sale by both tests, but the badge
         *    states the discount, and the discount it stated was zero. The
         *    theme's own badge has always guarded this (`$off ? … : ''` in
         *    components/product-card.blade.php) and the module did not, so
         *    turning the module ON introduced a badge advertising a saving of
         *    nothing, in the sale colour, on a card whose price block correctly
         *    showed no saving at all. Reproduced on /shop before the fix.
         *  - `$sale > 0` SKIPPED A PRODUCT MARKED DOWN TO FREE. The price block
         *    called that a sale; the badge did not.
         *
         * Below 1% the badge cannot state a true number, so the rule falls
         * through to the next one (new, then bestseller) rather than lying. The
         * plugin's `{off}` substitution is unchanged.
         */
        if ($c['sale_on'] && $product->isOnSale()) {
            $off = $product->discountPercent();

            if ($off >= 1) {
                return [
                    'text' => str_replace('{off}', (string) $off, (string) $c['sale_text']),
                    'colour' => $c['sale_color'],
                ];
            }
        }

        if ($c['new_on'] && $product->created_at !== null
            && $product->created_at->diffInDays(now()) < (int) $c['new_days']) {
            return ['text' => $c['new_text'], 'colour' => $c['new_color']];
        }

        if ($c['feat_on'] && (bool) $product->featured) {
            return ['text' => $c['feat_text'], 'colour' => $c['feat_color']];
        }

        // On, but nothing matched. The plugin returns an empty string here rather
        // than falling back to the theme's badge, and so does this.
        return null;
    }
}
