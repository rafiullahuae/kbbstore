<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → You may also like.                 (Lane PS)
 *
 * ▲ SINCE LANE RP2 THIS TAB DRIVES THREE BLOCKS: 1 more from the brand, 2 more
 * from the breadcrumb's category, 3 "You may also like" = the shop's best
 * sellers, with no product twice (App\Services\ProductRecs). The history
 * below is how the carousel began; its mixing rules (`rule`, `mix`, `fill`)
 * and Lane RP's tabs, routine and recently-viewed blocks are retired, and a
 * value still saved under one of those keys is never read.
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
         * ── ▲ THREE BLOCKS: BRAND, CATEGORY, BEST SELLERS. (Lane RP2) ─────────
         *
         * The owner changed the plan Lane RP shipped (2.60.428): "first block
         * will be brand … 2nd block will be category … 3rd block will be You
         * may also like but the products will be picked as best sellers … no
         * any repeat product". He asked for it, so it ships on (CLAUDE.md, 30
         * September). The two tabs, the listing-page hint, "Complete your
         * routine", "Continue shopping" and the old mixing rules are gone, and
         * so are their controls; a value saved for one of them is simply never
         * read again (RecsBlocksTest pins that the page still draws).
         */
        'brand_on' => ['bool', 'Block 1 · Show “More from {brand}”', true,
            'A carousel of more products from this product’s brand, best sellers first. Not drawn for a product with no brand, or a brand with nothing else to show.'],

        'brand_title' => ['text', 'Block 1 · Heading', '',
            'Leave empty for the standard heading, “More from” and the brand’s name.'],

        'brand_title_ar' => ['text', 'Block 1 · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'cat_on' => ['bool', 'Block 2 · Show “More {category}”', true,
            'A grid of more products from the category this product’s breadcrumb names, in stock first, then best sellers. Never repeats a product from block 1.'],

        'cat_title' => ['text', 'Block 2 · Heading', '',
            'Leave empty for the standard heading, “More” and the category’s name.'],

        'cat_title_ar' => ['text', 'Block 2 · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'enabled' => ['bool', 'Block 3 · Show “You may also like”', true,
            'A carousel of the shop’s best sellers, in stock first, that are not already in blocks 1 and 2. A product’s own picks (Catalog → Products → edit → You may also like) come first. Per-device visibility of all three blocks is the “You may also like” row on the Sections tab.'],

        /*
         * Block 3's STANDARD count — the number "You may also like" has had
         * since Lane PS, and a value he saved for it is kept. Not drawn on the
         * tab any more: the per-device counts below are the control, and
         * "Standard" there means this number for block 3, 12 for block 1 and
         * 10 for block 2.
         */
        'count' => ['range', 'Block 3 · Standard number of products', 12,
            'Used when the number is left on “Standard”.',
            ['min' => 4, 'max' => 24, 'step' => 1, 'unit' => '']],

        'title' => ['text', 'Block 3 · Heading', '',
            'Leave empty for the standard heading, “You may also like”.'],

        'title_ar' => ['text', 'Block 3 · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading, قد يعجبك أيضًا. Your English heading is never shown on the Arabic page.'],

        'eyebrow' => ['text', 'Block 3 · Small line above the heading', '',
            'Leave empty for the standard line, “Best sellers”.'],

        'eyebrow_ar' => ['text', 'Block 3 · Small line — Arabic', '',
            'Leave empty for the standard Arabic line.'],

        /*
         * ── SLIDER OR GRID, AND HOW MANY — PER DEVICE (Lane RP2) ─────────────
         *
         * The owner: "also give facility to choose slider or grid, and along
         * with number of products to display. such controls should be for
         * desktop and mobile seperately with global options."
         *
         * A GLOBAL pair per device, and the same four controls on each block,
         * each "Same as global" by default. Global "Standard" is the look the
         * page already has — brand slider, category grid, you-may-also-like
         * slider; 12, 10 and 12 — so applying this moves nothing until he
         * picks something. Laptop is from 901 px, phone below: the product
         * page's own breakpoint (kbb-product.css, the carousels' arrows).
         * Resolved by AlsoLikeSettings::layoutFor().
         */
        'g_layout_d' => ['select', 'Global · Laptop · slider or grid', 'std',
            'For all three blocks on a laptop, unless a block says otherwise below.', self::GLOBAL_LAYOUTS],
        'g_count_d' => ['select', 'Global · Laptop · number of products', 'std',
            'For all three blocks on a laptop (4–24), unless a block says otherwise below.', self::GLOBAL_COUNTS],
        'g_layout_m' => ['select', 'Global · Phone · slider or grid', 'std',
            'For all three blocks on a phone, unless a block says otherwise below.', self::GLOBAL_LAYOUTS],
        'g_count_m' => ['select', 'Global · Phone · number of products', 'std',
            'For all three blocks on a phone (4–24), unless a block says otherwise below.', self::GLOBAL_COUNTS],

        'brand_layout_d' => ['select', 'Block 1 · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'brand_count_d' => ['select', 'Block 1 · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'brand_layout_m' => ['select', 'Block 1 · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'brand_count_m' => ['select', 'Block 1 · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'cat_layout_d' => ['select', 'Block 2 · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'cat_count_d' => ['select', 'Block 2 · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'cat_layout_m' => ['select', 'Block 2 · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'cat_count_m' => ['select', 'Block 2 · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'also_layout_d' => ['select', 'Block 3 · Laptop · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'also_count_d' => ['select', 'Block 3 · Laptop · number of products', 'global', '4–24.', self::BLOCK_COUNTS],
        'also_layout_m' => ['select', 'Block 3 · Phone · slider or grid', 'global', '', self::BLOCK_LAYOUTS],
        'also_count_m' => ['select', 'Block 3 · Phone · number of products', 'global', '4–24.', self::BLOCK_COUNTS],

        'hide_oos' => ['bool', 'Hide out-of-stock products', true,
            'Leave sold-out products out of all three blocks. Hidden and unpublished products are never shown.'],

        'per_desktop' => ['select', 'Carousels · cards in view on a laptop', '5',
            'Blocks 1 and 3: how many cards fit across before the arrows take over.',
            ['3' => '3', '4' => '4', '5' => '5', '6' => '6']],

        /*
         * (Lane PX) "on product page, i want the same 2.3 cards to display by
         * default" — HomepageContent's `home_hb_per_m` set, plus the '3' this
         * control always had. A fractional count is printed into the same
         * `--ymal-m` the calc() already divides by; nothing measures anything.
         */
        'per_phone' => ['select', 'Carousels · cards in view on a phone', '2.3',
            'Blocks 1 and 3: how many cards fit across a phone before a swipe. A part-visible card at the screen edge tells a thumb there is more to swipe.',
            ['1' => '1', '1.5' => '1½ — the next one peeks', '2' => '2', '2.2' => '2.2 — a sliver of the next one',
                '2.3' => '2.3 — the next one peeks (recommended)', '2.5' => '2½ — the next one peeks', '3' => '3']],

        'arrows_m' => ['bool', 'Carousels · arrows on a phone', false,
            'Off: the peeking card shows there is more, and a swipe moves it. On: the two round arrows show on a phone too, beside the heading.'],

        'autoplay' => ['bool', 'Carousels · move on their own', false,
            'Advance the carousels every few seconds. They stop while the shopper hovers, touches or tabs into them, and never run for a visitor who has asked for reduced motion.'],

        'autoplay_s' => ['range', 'Carousels · seconds between moves', 5,
            'Only used when “Move on their own” is on.',
            ['min' => 3, 'max' => 15, 'step' => 1, 'unit' => 's']],

        'order' => ['select', 'Order of the three blocks', '123',
            'Top to bottom, at the foot of the product page.',
            self::ORDERS],
    ];

    /** The six orders of the three blocks. A select stores one of these or the default. */
    public const ORDERS = [
        '123' => '1 Brand · 2 Category · 3 You may also like',
        '132' => '1 Brand · 3 You may also like · 2 Category',
        '213' => '2 Category · 1 Brand · 3 You may also like',
        '231' => '2 Category · 3 You may also like · 1 Brand',
        '312' => '3 You may also like · 1 Brand · 2 Category',
        '321' => '3 You may also like · 2 Category · 1 Brand',
    ];

    /** Each block's own look when the global layout is "Standard". */
    public const STANDARD = [
        'brand' => ['layout' => 'slider', 'count' => 12],
        'cat' => ['layout' => 'grid', 'count' => 10],
        // Block 3's count is the `count` setting (12 unless he saved another).
        'also' => ['layout' => 'slider', 'count' => null],
    ];

    public const GLOBAL_LAYOUTS = [
        'std' => 'Standard — slider · grid · slider',
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
        'std' => 'Standard — 12 · 10 · 12',
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
            'The three blocks at the foot of every product page: 1 more from the brand, 2 more from the category, 3 “You may also like” (best sellers). No product is shown twice.',
            ['g_layout_d', 'g_count_d', 'g_layout_m', 'g_count_m',
                'brand_on', 'brand_layout_d', 'brand_count_d', 'brand_layout_m', 'brand_count_m', 'brand_title', 'brand_title_ar',
                'cat_on', 'cat_layout_d', 'cat_count_d', 'cat_layout_m', 'cat_count_m', 'cat_title', 'cat_title_ar',
                'enabled', 'also_layout_d', 'also_count_d', 'also_layout_m', 'also_count_m', 'title', 'title_ar', 'eyebrow', 'eyebrow_ar',
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
        $stdCount = $std['count'] ?? max(self::COUNT_MIN, min(self::COUNT_MAX, (int) ($c['count'] ?? 12)));
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
     * The heading and the eyebrow for the language this page is in.
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
            // Block 3 is the shop's best sellers now (Lane RP2), so its small
            // line says so — an interface string the Arabic side already has.
            'eyebrow' => $eyebrow !== '' ? $eyebrow : (string) __('store.product.recs_best_eyebrow'),
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
