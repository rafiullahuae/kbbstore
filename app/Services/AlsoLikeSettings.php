<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → You may also like.                 (Lane PS)
 *
 * ▲ SINCE LANE BC THIS TAB DRIVES THE FOOT OF THE PAGE: brand and category
 * (two tabs by default, or two blocks), an optional best-seller block, and
 * Continue shopping, with no product twice (App\Services\ProductRecs). See
 * SCHEMA's head. The history below is how the carousel began; its mixing
 * rules (`rule`, `mix`, `fill`), "Complete your routine" and 2.60.428's
 * `enabled` / `first` are retired, and a value still saved under one of those
 * keys is never read.
 *
 * The owner, 2 October:
 *
 *   "You may also like should be a slider on each product page, I need it
 *    carousel by suggesting products from the same brand and category mixed.
 *    Give us control to choose the products query what to show etc, or manual
 *    selection also."
 *
 * ── WHAT THE SECTION WAS ────────────────────────────────────────────────────
 *
 * Four cards from the product's own categories, best sellers first, laid out
 * on the shared product grid (`.rel.kbb-pgrid`) — no brand in the choice at
 * all, no carousel, no control of any kind, and FOUR hard-coded in
 * Store\ProductController::related().
 *
 * ── ▲ WHAT SHIPS ON, AND WHY THAT IS THE RULE THIS TIME ────────────────────
 *
 * He asked for the carousel and for the brand + category mix in as many words,
 * so CLAUDE.md's 30 September reversal applies: those are the shop's new state,
 * not switches he has to go and find. The defaults below ARE his request —
 * `rule => mix`, a carousel, twelve cards. The controls exist so he can take
 * any of it back.
 *
 * What he did NOT name ships at the value the page already had: five cards
 * across on a laptop and two on a phone are the counts the shared grid was
 * already drawing at 1280px and 390px (measured: 232.8px and 168px cards,
 * docs/lane-ps-shots/before-report.json), so the cards are the same size they
 * were, only now in one row. Autoplay is off. The wording is the interface
 * string it always was until he types his own.
 *
 * ── THE TITLE, IN BOTH LANGUAGES ────────────────────────────────────────────
 *
 * Blank means "the interface string", which is `store.product.related_heading`
 * and its Arabic draft `قد يعجبك أيضًا` in ArabicInterfaceDrafts — so a shop
 * that never touches the box keeps its translated heading in both languages.
 * A typed English title does NOT silently become the Arabic one: each language
 * has its own box, and an empty Arabic box falls back to the Arabic interface
 * string, never to his English.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through the one snapshot
 * SettingsService has already taken on the request — the same arrangement as
 * App\Services\ProductLayout.
 */
class AlsoLikeSettings
{
    /** Every key is stored as `settings.key` = PREFIX . <schema key>. */
    public const PREFIX = 'ymal_';

    /** key => [type, label, default, help, options] */
    public const SCHEMA = [
        /*
         * ── ▲ TABS, THEN CONTINUE SHOPPING. (Lane BC, the owner's third plan) ──
         *
         * "keep also the tab, more from anua, and more from toner, both tabs.
         *  and remove the second block complete your routine. keep such things
         *  by default rest will be off, and also give option to disabl the tabs
         *  and show the brand, and then the category block, but by default keep
         *  the tabs on, turn off the 2nd block and third will be continue
         *  shoping which we have already."
         *
         *   1  Brand and category — ONE block with two tabs (2.60.428's), or,
         *      with `pair` = blocks, a brand block then a category block (Lane
         *      RP2's). The category is the product's most specific one.
         *   2  Best sellers — Lane RP2's best-seller block. OFF by default.
         *   3  Continue shopping — the shopper's recently viewed, then best
         *      sellers (2.60.428's).
         *
         * "Complete your routine" is gone for good. He asked for all of this,
         * so it ships on (CLAUDE.md, 30 September). No product twice across
         * every block on the page (App\Services\ProductRecs).
         *
         * Keys that 2.60.428 shipped keep their meaning where they still mean
         * the same thing — `title` / `eyebrow` are the tab block's heading,
         * `count` its standard number, `recent_*` continue shopping — and a
         * key whose meaning changed was given a NEW name rather than reused, so
         * a value he saved for the old meaning cannot switch the new thing:
         * `enabled` (2.60.428's block 1 switch, possibly saved "on") is never
         * read, and the best-seller block is `best_on`; 2.60.428's `first`
         * ("brand" when there is no hint) is never read, and the opening tab is
         * `tab_first`.
         */
        'pair' => ['select', 'Brand and category · Show as', 'tabs',
            'Tabs: one block, “More from {brand}” | “More {category}”, both lists on the page for Google. Two separate blocks: the brand block, then the category block. Either way no product is in both.',
            ['tabs' => 'Tabs — one block, brand | category', 'blocks' => 'Two separate blocks — brand, then category']],

        'tab_first' => ['select', 'Tabs · Tab that opens first', 'auto',
            'Where the shopper came from: a brand page opens the brand tab, a category page the category tab, anything else (search, Google, the homepage) the brand tab.',
            ['auto' => 'Where the shopper came from', 'brand' => 'Always the brand', 'category' => 'Always the category']],

        'title' => ['text', 'Tabs · Heading', '',
            'Leave empty for the standard heading, “You may also like”.'],

        'title_ar' => ['text', 'Tabs · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading, قد يعجبك أيضًا. Your English heading is never shown on the Arabic page.'],

        'eyebrow' => ['text', 'Tabs · Small line above the heading', '',
            'Leave empty for the standard line, “More like this”.'],

        'eyebrow_ar' => ['text', 'Tabs · Small line — Arabic', '',
            'Leave empty for the standard Arabic line.'],

        /*
         * The tab block's STANDARD number: 2.60.428's "how many" for block 1,
         * so a value he saved for it is kept. Not drawn on the tab; the
         * per-device counts below are the control, and "Standard" there
         * means this number for the tabs.
         */
        'count' => ['range', 'Tabs · Standard number of products', 12,
            'Used when the number is left on “Standard”.',
            ['min' => 4, 'max' => 24, 'step' => 1, 'unit' => '']],

        'brand_on' => ['bool', 'Brand · Show “More from {brand}”', true,
            'More products from this product’s brand, best sellers first — the first tab, or the brand block. Not drawn for a product with no brand, or a brand with nothing else to show.'],

        'brand_title' => ['text', 'Brand · Heading', '',
            'The tab’s name, or the block’s heading. Leave empty for “More from” and the brand’s name.'],

        'brand_title_ar' => ['text', 'Brand · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'cat_on' => ['bool', 'Category · Show “More {category}”', true,
            'More products from this product’s most specific category — Toners, not Skincare above it — in stock first, then best sellers: the second tab, or the category block. Never repeats a product from the brand.'],

        'cat_title' => ['text', 'Category · Heading', '',
            'The tab’s name, or the block’s heading. Leave empty for “More” and the category’s name.'],

        'cat_title_ar' => ['text', 'Category · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'recent_on' => ['bool', 'Continue shopping · Show “Continue shopping”', true,
            'What this shopper looked at recently, then the shop’s best sellers. A first-time visitor and Google see best sellers. A product’s own picks (Catalog → Products → edit → You may also like) come after the recently viewed, unless the best-sellers block is on.'],

        'recent_title' => ['text', 'Continue shopping · Heading', '',
            'Leave empty for the standard heading, “Continue shopping”.'],

        'recent_title_ar' => ['text', 'Continue shopping · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        /* Continue shopping's STANDARD number: 2.60.428's, kept like `count`. */
        'recent_count' => ['range', 'Continue shopping · Standard number of products', 10,
            'Used when the number is left on “Standard”.',
            ['min' => 4, 'max' => 24, 'step' => 1, 'unit' => '']],

        'best_on' => ['bool', 'Best sellers · Show the best-sellers block', false,
            'A block of the shop’s best sellers, in stock first, that are not already shown above. While it is on, a product’s own picks lead it instead of Continue shopping.'],

        'best_title' => ['text', 'Best sellers · Heading', '',
            'Leave empty for the standard heading, “Best sellers”.'],

        'best_title_ar' => ['text', 'Best sellers · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        /*
         * ── SLIDER OR GRID, AND HOW MANY — PER DEVICE (Lane RP2) ─────────────
         *
         * The owner: "also give facility to choose slider or grid, and along
         * with number of products to display. such controls should be for
         * desktop and mobile seperately with global options."
         *
         * A GLOBAL pair per device, and the same four controls on each block,
         * each "Same as global" by default. Global "Standard" is each block's
         * own look (STANDARD below): sliders, the separate category block a
         * grid; 12, the category block and continue shopping 10. Laptop is
         * from 901 px, phone below: the product page's own breakpoint
         * (kbb-product.css, the carousels' arrows). Resolved by layoutFor().
         */
        'g_layout_d' => ['select', 'Global · Laptop · slider or grid', 'std',
            'For every block on a laptop, unless a block says otherwise below.', self::GLOBAL_LAYOUTS],
        'g_count_d' => ['select', 'Global · Laptop · number of products', 'std',
            'For every block on a laptop (4–24), unless a block says otherwise below.', self::GLOBAL_COUNTS],
        'g_layout_m' => ['select', 'Global · Phone · slider or grid', 'std',
            'For every block on a phone, unless a block says otherwise below.', self::GLOBAL_LAYOUTS],
        'g_count_m' => ['select', 'Global · Phone · number of products', 'std',
            'For every block on a phone (4–24), unless a block says otherwise below.', self::GLOBAL_COUNTS],

        'tabs_layout_d' => ['select', 'Tabs · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'tabs_count_d' => ['select', 'Tabs · Laptop · number of products', 'global', '4–24, in each tab.', self::BLOCK_COUNTS],
        'tabs_layout_m' => ['select', 'Tabs · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'tabs_count_m' => ['select', 'Tabs · Phone · number of products', 'global', '4–24, in each tab.', self::BLOCK_COUNTS],

        'brand_layout_d' => ['select', 'Brand block · Laptop · slider or grid', 'global', 'Two separate blocks only.', self::BLOCK_LAYOUTS],
        'brand_count_d' => ['select', 'Brand block · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'brand_layout_m' => ['select', 'Brand block · Phone · slider or grid', 'global', 'Two separate blocks only.', self::BLOCK_LAYOUTS],
        'brand_count_m' => ['select', 'Brand block · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'cat_layout_d' => ['select', 'Category block · Laptop · slider or grid', 'global', 'Two separate blocks only.', self::BLOCK_LAYOUTS],
        'cat_count_d' => ['select', 'Category block · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'cat_layout_m' => ['select', 'Category block · Phone · slider or grid', 'global', 'Two separate blocks only.', self::BLOCK_LAYOUTS],
        'cat_count_m' => ['select', 'Category block · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'recent_layout_d' => ['select', 'Continue shopping · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'recent_count_d' => ['select', 'Continue shopping · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'recent_layout_m' => ['select', 'Continue shopping · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'recent_count_m' => ['select', 'Continue shopping · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'best_layout_d' => ['select', 'Best sellers · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'best_count_d' => ['select', 'Best sellers · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'best_layout_m' => ['select', 'Best sellers · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'best_count_m' => ['select', 'Best sellers · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'hide_oos' => ['bool', 'Hide out-of-stock products', true,
            'Leave sold-out products out of every block. Hidden and unpublished products are never shown.'],

        'per_desktop' => ['select', 'Carousels · cards in view on a laptop', '5',
            'Every slider: how many cards fit across before the arrows take over.',
            ['3' => '3', '4' => '4', '5' => '5', '6' => '6']],

        /*
         * (Lane PX) "on product page, i want the same 2.3 cards to display by
         * default" — HomepageContent's `home_hb_per_m` set, plus the '3' this
         * control always had. A fractional count is printed into the same
         * `--ymal-m` the calc() already divides by; nothing measures anything.
         */
        'per_phone' => ['select', 'Carousels · cards in view on a phone', '2.3',
            'Every slider: how many cards fit across a phone before a swipe. A part-visible card at the screen edge tells a thumb there is more to swipe.',
            ['1' => '1', '1.5' => '1½ — the next one peeks', '2' => '2', '2.2' => '2.2 — a sliver of the next one',
                '2.3' => '2.3 — the next one peeks (recommended)', '2.5' => '2½ — the next one peeks', '3' => '3']],

        'arrows_m' => ['bool', 'Carousels · arrows on a phone', false,
            'Off: the peeking card shows there is more, and a swipe moves it. On: the two round arrows show on a phone too, beside the heading.'],

        'autoplay' => ['bool', 'Carousels · move on their own', false,
            'Advance the brand and category sliders every few seconds (Continue shopping never moves on its own). They stop while the shopper hovers, touches or tabs into them, and never run for a visitor who has asked for reduced motion.'],

        'autoplay_s' => ['range', 'Carousels · seconds between moves', 5,
            'Only used when “Move on their own” is on.',
            ['min' => 3, 'max' => 15, 'step' => 1, 'unit' => 's']],

        'order' => ['select', 'Order of the blocks', '123',
            'Top to bottom, at the foot of the product page. A block that is off is skipped.',
            self::ORDERS],
    ];

    /**
     * The six orders. 1 and 3 are the digits 2.60.428 gave the tabs and
     * Continue shopping, so an order he saved then still means the same two
     * blocks; 2 was "Complete your routine" and is the best-seller block now.
     */
    public const ORDERS = [
        '123' => 'Brand & category · Best sellers · Continue shopping',
        '132' => 'Brand & category · Continue shopping · Best sellers',
        '213' => 'Best sellers · Brand & category · Continue shopping',
        '231' => 'Best sellers · Continue shopping · Brand & category',
        '312' => 'Continue shopping · Brand & category · Best sellers',
        '321' => 'Continue shopping · Best sellers · Brand & category',
    ];

    /**
     * Each block's own look when the global setting is "Standard". A null
     * count is read from the setting named by `key` (one he may have saved).
     */
    public const STANDARD = [
        'tabs' => ['layout' => 'slider', 'count' => null, 'key' => 'count'],
        'brand' => ['layout' => 'slider', 'count' => 12],
        'cat' => ['layout' => 'grid', 'count' => 10],
        'recent' => ['layout' => 'slider', 'count' => null, 'key' => 'recent_count'],
        'best' => ['layout' => 'slider', 'count' => 12],
    ];

    public const GLOBAL_LAYOUTS = [
        'std' => 'Standard — sliders; the category block a grid',
        'slider' => 'Slider',
        'grid' => 'Grid',
    ];

    public const BLOCK_LAYOUTS = [
        'global' => 'Same as global',
        'slider' => 'Slider',
        'grid' => 'Grid',
    ];

    public const COUNT_MIN = 4;

    public const COUNT_MAX = 24;

    public const GLOBAL_COUNTS = [
        'std' => 'Standard — 12; category block and continue shopping 10',
        '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11',
        '12' => '12', '13' => '13', '14' => '14', '15' => '15', '16' => '16', '17' => '17', '18' => '18',
        '19' => '19', '20' => '20', '21' => '21', '22' => '22', '23' => '23', '24' => '24',
    ];

    public const BLOCK_COUNTS = [
        'global' => 'Same as global',
        '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11',
        '12' => '12', '13' => '13', '14' => '14', '15' => '15', '16' => '16', '17' => '17', '18' => '18',
        '19' => '19', '20' => '20', '21' => '21', '22' => '22', '23' => '23', '24' => '24',
    ];

    /** Same shape as ProductLayout::TABS — the console draws it the same way. */
    public const TABS = [
        'ymal' => ['You may also like',
            'The blocks at the foot of every product page: brand and category (two tabs, or two blocks), the best sellers (off unless you switch it on), and Continue shopping. No product is shown twice.',
            ['pair', 'tab_first', 'brand_on', 'cat_on', 'recent_on', 'best_on',
                'g_layout_d', 'g_count_d', 'g_layout_m', 'g_count_m',
                'tabs_layout_d', 'tabs_count_d', 'tabs_layout_m', 'tabs_count_m', 'title', 'title_ar', 'eyebrow', 'eyebrow_ar',
                'brand_layout_d', 'brand_count_d', 'brand_layout_m', 'brand_count_m', 'brand_title', 'brand_title_ar',
                'cat_layout_d', 'cat_count_d', 'cat_layout_m', 'cat_count_m', 'cat_title', 'cat_title_ar',
                'recent_layout_d', 'recent_count_d', 'recent_layout_m', 'recent_count_m', 'recent_title', 'recent_title_ar',
                'best_layout_d', 'best_count_d', 'best_layout_m', 'best_count_m', 'best_title', 'best_title_ar',
                'hide_oos', 'per_desktop', 'per_phone', 'arrows_m', 'autoplay', 'autoplay_s', 'order']],
    ];

    /**
     * `invalid => default` and `clamp => true`, the slider screens' point on
     * every axis: a select stores one of its own options or the default, and a
     * range is pulled into its bounds. `blank => keep` because an emptied
     * heading box MEANS "use the standard wording" and the page reads '' as
     * exactly that. `markup => strip`: a heading is wording, not markup — it is
     * printed through Blade's escaper regardless, which is what makes it safe.
     * `bool => words`, so a posted "off" or "false" is off.
     */
    public const POLICY = [
        'max' => 60,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> every value, saved or shipped */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    /** @return array<string, mixed> the shipped value of every key */
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

    /** The admin payload: ModuleSchema::tabs(), like Product page's Layout tabs. */
    public function tabs(): array
    {
        return ModuleSchema::tabs(self::SCHEMA, self::TABS, $this->all(), self::POLICY);
    }

    /**
     * One block's look on each device: slider or grid, and how many cards.
     * Block → global → the block's standard, in that order, each value one of
     * its select's own options (anything else is the default, by ModuleSchema;
     * read again here so a hand-edited row cannot reach a class or a count).
     *
     * @param  array<string, mixed>  $c
     * @return array{d: string, m: string, nd: int, nm: int, n: int}
     */
    public static function layoutFor(array $c, string $block): array
    {
        $std = self::STANDARD[$block];
        $stdCount = $std['count'] ?? max(self::COUNT_MIN, min(self::COUNT_MAX, (int) ($c[$std['key']] ?? self::SCHEMA[$std['key']][2])));
        $out = [];

        foreach (['d', 'm'] as $dev) {
            $own = (string) ($c[$block.'_layout_'.$dev] ?? 'global');
            $glob = (string) ($c['g_layout_'.$dev] ?? 'std');
            $layout = in_array($own, ['slider', 'grid'], true) ? $own
                : (in_array($glob, ['slider', 'grid'], true) ? $glob : $std['layout']);

            $ownN = (string) ($c[$block.'_count_'.$dev] ?? 'global');
            $globN = (string) ($c['g_count_'.$dev] ?? 'std');
            $n = $ownN !== 'global' && isset(self::BLOCK_COUNTS[$ownN]) ? (int) $ownN
                : ($globN !== 'std' && isset(self::GLOBAL_COUNTS[$globN]) ? (int) $globN : $stdCount);

            $out[$dev] = $layout;
            $out['n'.$dev] = max(self::COUNT_MIN, min(self::COUNT_MAX, $n));
        }

        $out['n'] = max($out['nd'], $out['nm']);

        return $out;
    }

    /**
     * The tab block's heading and eyebrow for the language this page is in.
     *
     * The interface string when the box is empty, so the translation system
     * keeps answering for a shop that never typed anything.
     *
     * @param  array<string, mixed>  $c
     * @return array{title: string, eyebrow: string}
     */
    public static function wording(array $c): array
    {
        $ar = ! Locale::isDefault() && Locale::current() === 'ar';

        $title = trim((string) ($ar ? ($c['title_ar'] ?? '') : ($c['title'] ?? '')));
        $eyebrow = trim((string) ($ar ? ($c['eyebrow_ar'] ?? '') : ($c['eyebrow'] ?? '')));

        return [
            'title' => $title !== '' ? $title : (string) __('store.product.related_heading'),
            // The tab block's small line, as 2.60.428 printed it.
            'eyebrow' => $eyebrow !== '' ? $eyebrow : (string) __('store.product.recs_more_eyebrow'),
        ];
    }

    private static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }
}
