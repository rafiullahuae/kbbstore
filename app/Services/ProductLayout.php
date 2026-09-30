<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Appearance → Product page → Layout: the spacing and the type of one product
 * page.                                                        (Lane PDP2, R4)
 *
 * The owner, 30 September:
 *
 *   "also i have control on the product page spacing between sections and
 *    elements etc. and fonts sizes control etc. pleas give me proper tabs for
 *    that on the product page > Layout."
 *
 * ── WHAT THE SCREEN WAS, CHECKED RATHER THAN REMEMBERED ─────────────────────
 *
 * `Appearance → Product page` was a FLAT LIST of seventeen on/off switches, one
 * per device, driven by App\Services\ProductSections::REGISTRY through
 * Admin\ProductPageApiController and drawn by renderProductPage() in
 * resources/views/admin/app.blade.php. There was no spacing control, no
 * font-size control and no tab grouping anywhere on it. The Ledger page shipped
 * three rounds of numbers — 19px/500 on a phone, 30px/500 on a laptop, a 20px
 * seam above every hairline — and not one of them had a handle.
 *
 * ── ▲ EVERY FIELD SHIPS AT THE VALUE THE PAGE RENDERS TODAY ─────────────────
 *
 * He asked for THE CONTROLS. He did not ask for any particular value, so this
 * is the half of CLAUDE.md rule 1 that did NOT reverse on 30 September: nothing
 * moves until he moves a slider. The reversal ("whatever i said, keep applying
 * on the site") is about outcomes he named; a default nobody chose is the thing
 * that rule's surviving half forbids.
 *
 * So storefrontCss() answers the EMPTY STRING while every value is at its
 * shipped default, exactly as SetAppearance::storefrontCss() does and for the
 * same reason: restating today's numbers would be correct in pixels and wrong
 * in bytes. partials/product-layout-css.blade.php emits no <style> element at
 * all for an empty string, so the product page is byte-identical to the one
 * that ships today and StorefrontEnglishUnchangedTest stays green without an
 * approved rule.
 *
 * Every default below was copied off resources/css/kbb/kbb-product.css
 * declaration by declaration, and ProductLayoutDefaultsTest asserts each one
 * against the literal it came from — including the `var(--pl-…, <literal>)`
 * FALLBACK, which is the number a page renders when this class emits nothing.
 * A default and a fallback that disagree is a package that moves the page the
 * moment it is applied, which is the one thing this round may not do.
 *
 * ── HOW IT REACHES THE PAGE ─────────────────────────────────────────────────
 *
 * As custom properties, in ONE <style> block in the <head>, read by
 * `var(--pl-x, <the literal that was there>)` in kbb-product.css. No
 * JavaScript: CLAUDE.md rule 4, and two tests forbid the element-measuring APIs
 * by name. Where a cap is derived from a size — the short description's
 * three-line clamp and its fade — the derivation is written as calc() against
 * the same variables, so the cap follows the type instead of drifting away from
 * it.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through the one Setting::map()
 * snapshot the header has already warmed on this request. all() runs once per
 * page, so StorefrontQueryBudgetTest does not move.
 */
class ProductLayout
{
    /** Every key is stored as `settings.key` = PREFIX . <schema key>. */
    public const PREFIX = 'pdplay_';

    /**
     * Sizes are stored in TENTHS OF A PIXEL and line heights in HUNDREDTHS.
     *
     * Not decoration: the page's own numbers are 13.5px, 12.5px and 1.62, and
     * ModuleSchema's `range` is an integer — it has no decimal arm and should
     * not grow one for this screen. A tenth is the smallest step the shop's
     * type actually uses, the console divides by ten to draw the label, and
     * `19` can never be confused with `190` because nothing but this class and
     * its screen ever reads the stored number.
     */
    public const TENTH = 10;

    /** key => [type, label, default, help, options] */
    public const SCHEMA = [

        /* ═══════════ SPACING · the page ═══════════════════════════════════ */

        // .sec{padding:34px 0} — kbb-product.css:309. The band above and below
        // "Product details", "Reviews" and "You may also like", which is what
        // "the gaps between the page's sections" means on this page.
        'sec_pad' => ['range', 'Space between page sections', 34,
            'Above and below “Product details”, the reviews and “You may also like”. The gap between two sections is two of these.',
            ['min' => 0, 'max' => 90, 'step' => 2, 'unit' => 'px']],

        // @media(max-width:880px){.pdp .buybox{padding-block-start:22px}}
        'buybox_gap' => ['range', 'Space under the photograph, on a phone', 22,
            'Between the gallery thumbnails and the product name. Phones only — on a laptop the picture sits beside the buy column rather than above it.',
            ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],

        // @media(max-width:880px){.pdp .gthumbs{gap:8px}}
        'thumb_gap' => ['range', 'Gap between gallery thumbnails', 8,
            'The little pictures that float on the bottom edge of the photograph.',
            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],

        // .details .dtabbar{gap:22px} — the Ledger rule, which beats the
        // shipped `.dtabbar{gap:4px}` two classes to one.
        'tab_gap' => ['range', 'Gap between the detail tabs', 22,
            'Between “Description”, “Ingredients” and “How to use” in the tab row.',
            ['min' => 4, 'max' => 48, 'step' => 1, 'unit' => 'px']],

        // .details .dtabbar{margin-block-end:18px}
        'tab_body_gap' => ['range', 'Space under the detail tab row', 18,
            'Between the tab row and the text it opens.',
            ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],

        /* ═══════════ SPACING · the buy column ═════════════════════════════ */

        // .pdp .bb-head{margin-block:8px 0}
        'head_gap' => ['range', 'Space above the product name', 8,
            'Between the brand line and the row that carries the name and the price.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        // .pdp .bb-head{column-gap:16px}
        'name_price_gap' => ['range', 'Gap between the name and the price', 16,
            'They share one row — the name on the left, the price on the right. This is the channel between them.',
            ['min' => 4, 'max' => 48, 'step' => 1, 'unit' => 'px']],

        // .pdp .cap-area{margin-block:10px 0} and .pdp .bb-rate{margin-block:10px 0}
        'rate_gap' => ['range', 'Space above the rating', 10,
            'Above the stars, the score and the thin bar under them. Both rating rows move together, so changing Store → Ecommerce → Product page → Review badges cannot lose it.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        /*
         * THE SEAM, AND IT IS TWO NUMBERS BECAUSE IT IS TWO GAPS.
         *
         * Every block in the buy column is separated from the one above it by a
         * hairline rule owned by the block BELOW — the short description, the
         * options label and the stock line all carry
         * `margin-block-start:20px; padding-block-start:20px`. The first number
         * is the air above the line, the second is the air under it. Naming
         * them as one control would have meant one of the two moving for a
         * reason the owner did not ask for.
         */
        'rule_gap' => ['range', 'Space above each dividing line', 20,
            'The hairline rules that separate the blurb, the options and the stock line. This is the air above the line.',
            ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'rule_pad' => ['range', 'Space below each dividing line', 20,
            'The air between the line and the block under it.',
            ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],

        // .pdp .trust{margin-block-start:22px} — 22 and not 20, which is the
        // sheet's own number and the reason this is its own control rather
        // than a fourth reader of `rule_gap`.
        'trust_gap' => ['range', 'Space above the trust lines', 22,
            'Above “Authentic, sourced direct”, the delivery line and the rest. The sheet has always used a slightly wider gap here than for the other seams.',
            ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],

        // .pdp .trust{gap:10px}
        'trust_line_gap' => ['range', 'Space between the trust lines', 10,
            'Between one trust line and the next.',
            ['min' => 0, 'max' => 30, 'step' => 1, 'unit' => 'px']],

        // .pdp .paychips{margin-block-start:12px}
        'chips_gap' => ['range', 'Space above the payment icons', 12,
            'Above the Tabby / Tamara / Visa row.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        /* ═══════════ TYPE · the buy column ════════════════════════════════ */

        // .pdp .bb-title{font-size:19px;font-weight:500} — round 2, option B.
        'title_m' => ['range', 'Product name · phone', 190,
            'The size he asked about: “in mobile you have used big bold font, whichi dont’ want”. 19px is what round 2 settled on.',
            ['min' => 140, 'max' => 280, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // @media(min-width:881px){.pdp .bb-title{font-size:30px}}
        'title_d' => ['range', 'Product name · laptop', 300,
            'The width the page turns at is 881px, which is this stylesheet’s own breakpoint.',
            ['min' => 180, 'max' => 440, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        'title_w' => ['select', 'Product name weight', '500',
            'Shared by both widths. 500 is what he approved on the laptop and what round 2 brought down to the phone.',
            ['400' => 'Light (400)', '500' => 'Regular (500)', '600' => 'Medium (600)', '700' => 'Bold (700)']],

        // .pdp .bb-head .bb-price .now{font-size:22px;font-weight:800}
        'price_s' => ['range', 'Price', 220, 'The live price, the biggest number on the page.',
            ['min' => 140, 'max' => 400, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        'price_w' => ['select', 'Price weight', '800', '',
            ['500' => 'Regular (500)', '600' => 'Medium (600)', '700' => 'Bold (700)', '800' => 'Heavy (800)']],
        // .pdp .bb-head .bb-price s{font-size:12.5px}
        'was_s' => ['range', 'Struck-out price', 125, 'The original price, above the live one.',
            ['min' => 90, 'max' => 220, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // .pdp .bb-head .bb-price .off{font-size:10px}
        'off_s' => ['range', 'Discount badge', 100, 'The “−25%” chip under the price.',
            ['min' => 70, 'max' => 180, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // .pdp .bb-vat{font-size:11px}
        'vat_s' => ['range', 'VAT line', 110, 'The tax sentence under the price. Its wording is Store → Ecommerce → Tax’s, not this screen’s.',
            ['min' => 80, 'max' => 180, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // .pdp .bb-rate{font-size:12px} and .pdp .cap-area .sr-capbar{font-size:12px}
        'rate_s' => ['range', 'Rating line', 120, 'The stars, the score and the review count.',
            ['min' => 90, 'max' => 200, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // .pdp .bb-desc{font-size:13.5px;line-height:1.62}
        'desc_s' => ['range', 'Short description', 135, 'The blurb above the options, the one with “Read more ↓” under it.',
            ['min' => 100, 'max' => 200, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        'desc_lh' => ['range', 'Short description line spacing', 162,
            'How far apart its lines sit. The three-line cap and the fade under it follow this number, so the blurb keeps showing three lines whatever it is set to.',
            ['min' => 110, 'max' => 240, 'step' => 2, 'unit' => '', 'scale' => 100]],
        // .pdp .trust .ti{font-size:12.5px}
        'trust_s' => ['range', 'Trust lines', 125, 'Authentic, delivery, returns, pay later.',
            ['min' => 90, 'max' => 200, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],

        /* ═══════════ TYPE · sections and tabs ═════════════════════════════ */

        // .sec h2{font-size:22px;font-weight:600}
        'heading_s' => ['range', 'Section headings', 220,
            '“Product details”, “Customer reviews”, “You may also like”.',
            ['min' => 140, 'max' => 400, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        'heading_w' => ['select', 'Section heading weight', '600', '',
            ['400' => 'Light (400)', '500' => 'Regular (500)', '600' => 'Medium (600)', '700' => 'Bold (700)', '800' => 'Heavy (800)']],
        // .details .dtab{font-size:13.5px}
        'tab_s' => ['range', 'Detail tab labels', 135,
            'The words in the tab row itself — Description, Ingredients, How to use.',
            ['min' => 100, 'max' => 220, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        // .dcontent{font-size:13.5px;line-height:1.7}
        'body_s' => ['range', 'Detail tab text', 135, 'The body text inside an opened tab.',
            ['min' => 100, 'max' => 220, 'step' => 5, 'unit' => 'px', 'scale' => self::TENTH]],
        'body_lh' => ['range', 'Detail tab line spacing', 170,
            'How far apart the lines of that text sit.',
            ['min' => 110, 'max' => 240, 'step' => 2, 'unit' => '', 'scale' => 100]],
    ];

    /**
     * Four tabs, and the split is by WHAT MOVES rather than by CSS property.
     *
     * Same shape as ProductStyles::TABS and NewsletterSettings::TABS —
     * `tab => [label, description, [keys]]` — because the console draws all
     * three with the same renderer and a screen that invents its own shape is a
     * screen the next reader has to learn separately.
     */
    public const TABS = [
        'sp_page' => ['Spacing · Page',
            'The gaps between the big blocks of the page — the picture, the buy column, and the three sections under them.',
            ['sec_pad', 'buybox_gap', 'thumb_gap', 'tab_gap', 'tab_body_gap']],
        'sp_buy' => ['Spacing · Buy column',
            'The gaps between the elements inside the buy column: the name, the price, the rating, the hairline seams, the trust lines and the payment icons.',
            ['head_gap', 'name_price_gap', 'rate_gap', 'rule_gap', 'rule_pad',
                'trust_gap', 'trust_line_gap', 'chips_gap']],
        'ty_buy' => ['Type · Buy column',
            'Font sizes and weights for the top of the page. The product name has a phone size and a laptop size because those are the two numbers the page really draws.',
            ['title_m', 'title_d', 'title_w', 'price_s', 'price_w', 'was_s', 'off_s',
                'vat_s', 'rate_s', 'desc_s', 'desc_lh', 'trust_s']],
        'ty_sec' => ['Type · Sections & tabs',
            'The headings under the buy column, and the detail tabs.',
            ['heading_s', 'heading_w', 'tab_s', 'body_s', 'body_lh']],
    ];

    /**
     * This screen's point on ModuleSchema's policy axes.
     *
     * `invalid => default` with `clamp => true`: every numeric control here is a
     * slider and a slider cannot emit an out-of-range value, so a POST that does
     * is not worth a 422 — it is answered with the shipped number, which is the
     * only value on this screen that is known to be safe to print into a
     * stylesheet.
     *
     * `markup => strip` is declared and never exercised: THERE IS NO TEXT FIELD
     * ON THIS SCREEN AND THERE MUST NOT BE. Every value here is printed into a
     * <style> element, where Blade's escaper is a hazard rather than a defence
     * (an entity inside <style> is handed to the CSS parser as its five
     * characters), so the only safe thing to print is a value that cannot
     * contain anything but digits. A range casts to int and a select stores one
     * of its own option keys; nothing else can reach css(). Rule 5.
     */
    public const POLICY = [
        'max' => 60,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
        'markup' => 'strip',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Every value, saved or shipped.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    /** The shipped value of every key. @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $def) => $def[2], self::SCHEMA);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }
    }

    /**
     * The normalised schema, memoised inside ModuleSchema rather than in a
     * static here — one piece of process-level state with one registered reset,
     * which is what StaticMemoIsolationTest asks for.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    /**
     * The block the storefront emits — or the EMPTY STRING while nothing has
     * moved.
     *
     * The empty answer is the whole of "applying this package moves not one
     * pixel" on the markup side. See this class's header.
     */
    public function storefrontCss(): string
    {
        $values = $this->all();

        return $values == self::defaults() ? '' : self::css($values);
    }

    /**
     * A hundredths (or tenths) integer as a decimal, locale-proof.
     *
     * `number_format` rather than string interpolation because a float printed
     * under a locale that uses a comma as its decimal separator is a CSS syntax
     * error, and this string goes straight into a stylesheet.
     */
    private static function num(int $value, int $of): string
    {
        $out = rtrim(rtrim(number_format($value / $of, 3, '.', ''), '0'), '.');

        return $out === '' || $out === '-' ? '0' : $out;
    }

    /** A tenths-of-a-pixel integer as a CSS length. */
    private static function px(int $value): string
    {
        return self::num($value, self::TENTH).'px';
    }

    /**
     * One of five weights, chosen by a value that can only ever be one of them.
     *
     * ModuleSchema's `select` cast already refuses anything that is not a key
     * of the schema's own option list, so this is the SECOND lock and not the
     * first: `settings` is a table, the owner has a shell on the live box
     * (CLAUDE.md, 24 September 2026), and a row written by hand would otherwise
     * land in a stylesheet. Anything unrecognised returns the shipped default,
     * so this cannot emit a value it was not written with. Rule 5.
     */
    private static function weight(mixed $value, string $fallback): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, ['400', '500', '600', '700', '800'], true) ? $value : $fallback;
    }

    /**
     * Every custom property the product page reads.
     *
     * The names are `--pl-…`, and each one is read in kbb-product.css as
     * `var(--pl-x, <the literal that was there before this screen existed>)`.
     * That pairing is what makes the empty block above safe: with no <style>
     * element at all every rule falls back to the number it has always had.
     *
     * @param  array<string, mixed>  $c
     */
    public static function css(array $c): string
    {
        $n = static fn (string $k): int => (int) $c[$k];

        $vars = [
            /* spacing · page */
            '--pl-sec-pad:'.$n('sec_pad').'px',
            '--pl-buybox-gap:'.$n('buybox_gap').'px',
            '--pl-thumb-gap:'.$n('thumb_gap').'px',
            '--pl-tab-gap:'.$n('tab_gap').'px',
            '--pl-tab-body-gap:'.$n('tab_body_gap').'px',

            /* spacing · buy column */
            '--pl-head-gap:'.$n('head_gap').'px',
            '--pl-np-gap:'.$n('name_price_gap').'px',
            '--pl-rate-gap:'.$n('rate_gap').'px',
            '--pl-rule-gap:'.$n('rule_gap').'px',
            '--pl-rule-pad:'.$n('rule_pad').'px',
            '--pl-trust-gap:'.$n('trust_gap').'px',
            '--pl-trust-line-gap:'.$n('trust_line_gap').'px',
            '--pl-chips-gap:'.$n('chips_gap').'px',

            /* type · buy column */
            '--pl-title-m:'.self::px($n('title_m')),
            '--pl-title-d:'.self::px($n('title_d')),
            '--pl-title-w:'.self::weight($c['title_w'] ?? null, '500'),
            '--pl-price-s:'.self::px($n('price_s')),
            '--pl-price-w:'.self::weight($c['price_w'] ?? null, '800'),
            '--pl-was-s:'.self::px($n('was_s')),
            '--pl-off-s:'.self::px($n('off_s')),
            '--pl-vat-s:'.self::px($n('vat_s')),
            '--pl-rate-s:'.self::px($n('rate_s')),
            '--pl-desc-s:'.self::px($n('desc_s')),
            // Unitless. The blurb's three-line cap and its fade are calc()s
            // against this same number, so they cannot drift away from it.
            '--pl-desc-lh:'.self::num($n('desc_lh'), 100),
            '--pl-trust-s:'.self::px($n('trust_s')),

            /* type · sections and tabs */
            '--pl-heading-s:'.self::px($n('heading_s')),
            '--pl-heading-w:'.self::weight($c['heading_w'] ?? null, '600'),
            '--pl-tab-s:'.self::px($n('tab_s')),
            '--pl-body-s:'.self::px($n('body_s')),
            '--pl-body-lh:'.self::num($n('body_lh'), 100),
        ];

        return ':root{'.implode(';', $vars).'}';
    }
}
