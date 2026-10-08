<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → You may also like.                 (Lane PS)
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

    /** The rules the section can fill itself from. */
    public const RULES = [
        'mix' => 'Same brand + same category, mixed',
        'brand' => 'Same brand',
        'category' => 'Same category',
        'best' => 'Best sellers (whole shop)',
        'newest' => 'Newest (whole shop)',
        'sale' => 'On sale (whole shop)',
        'manual' => 'Manual picks only',
    ];

    /** key => [type, label, default, help, options] */
    public const SCHEMA = [
        'enabled' => ['bool', 'Show “You may also like”', true,
            'The carousel at the foot of every product page. Per-device visibility is the “You may also like” row on the Sections tab.'],

        'rule' => ['select', 'What to show', 'mix',
            'Where the products come from. A product with its own picks (Catalog → Products → edit → You may also like) can override this.',
            self::RULES],

        'mix' => ['select', 'Brand : category mix', '1:1',
            'For “Same brand + same category, mixed”: how many of each, in turn. 2 : 1 is two from the brand, then one from the category, and again.',
            ['1:1' => '1 : 1 — alternate', '2:1' => '2 : 1 — more brand', '1:2' => '1 : 2 — more category',
                '3:1' => '3 : 1', '1:3' => '1 : 3']],

        'fill' => ['bool', 'Top up when short', true,
            'When a brand or category has too few products, finish the row from the parent category, then the shop’s best sellers.'],

        'count' => ['range', 'How many products', 12,
            'The most the carousel will hold — in each tab, when Block 1 · Layout is two tabs.',
            ['min' => 4, 'max' => 24, 'step' => 1, 'unit' => '']],

        'hide_oos' => ['bool', 'Hide out-of-stock products', true,
            'Leave sold-out products out of the carousel. Hidden and unpublished products are never shown.'],

        'per_desktop' => ['select', 'Cards in view on a laptop', '5',
            'How many cards fit across before the arrows take over. 5 is the size the cards already were.',
            ['3' => '3', '4' => '4', '5' => '5', '6' => '6']],

        /*
         * ▲ 2 -> 2.3, AND THE OWNER ASKED FOR IT.                  (Lane PX)
         *
         * "on product page, i want the same 2.3 cards to display by default" —
         * the treatment the homepage carousels got in 2.60.373. The options
         * are HomepageContent's `home_hb_per_m` set, so the two screens offer
         * the same choices, plus the '3' this control always had (a shop that
         * saved 3 keeps it). A fractional count is printed into the same
         * `--ymal-m` the calc() already divides by; nothing measures anything.
         * Laptop is untouched: per_desktop still 5, the same card.
         */
        'per_phone' => ['select', 'Cards in view on a phone', '2.3',
            'How many cards fit across a phone before a swipe. A part-visible card at the screen edge tells a thumb there is more to swipe.',
            ['1' => '1', '1.5' => '1½ — the next one peeks', '2' => '2', '2.2' => '2.2 — a sliver of the next one',
                '2.3' => '2.3 — the next one peeks (recommended)', '2.5' => '2½ — the next one peeks', '3' => '3']],

        // (Lane PX) Off, as on the homepage carousels: on a phone the peeking
        // card says there is more and a swipe moves it.
        'arrows_m' => ['bool', 'Arrows on a phone', false,
            'Off: the peeking card shows there is more, and a swipe moves it. On: the two round arrows show on a phone too, beside the heading.'],

        'autoplay' => ['bool', 'Move on its own', false,
            'Advance the carousel every few seconds. It stops while the shopper hovers, touches or tabs into it, and never runs for a visitor who has asked for reduced motion.'],

        'autoplay_s' => ['range', 'Seconds between moves', 5,
            'Only used when “Move on its own” is on.',
            ['min' => 3, 'max' => 15, 'step' => 1, 'unit' => 's']],

        'title' => ['text', 'Heading', '',
            'Leave empty for the standard heading, “You may also like”.'],

        'title_ar' => ['text', 'Heading — Arabic', '',
            'Leave empty for the standard Arabic heading, قد يعجبك أيضًا. Your English heading is never shown on the Arabic page.'],

        'eyebrow' => ['text', 'Small line above the heading', '',
            'Leave empty for the standard line: “More like this” over the two tabs, “Complete your routine” over one row.'],

        'eyebrow_ar' => ['text', 'Small line — Arabic', '',
            'Leave empty for the standard Arabic line.'],

        /*
         * ── ▲ THE THREE BLOCKS, AND THEY SHIP ON BECAUSE HE ASKED. (Lane RP)
         *
         * "1st a SLIDER, 2nd a GRID, 3rd a SLIDER", the first one opening on
         * the brand or the category the shopper came from. CLAUDE.md's 30
         * September reversal: what he asked for is the shop's new state, so
         * `layout => tabs`, both new blocks on. Every one of them can be taken
         * back here; `layout => one` and both blocks off is the page as it was,
         * byte for byte (RecsBlocksTest pins that).
         */
        'layout' => ['select', 'Block 1 · Layout', 'tabs',
            'Two tabs: “More from {brand}” and “More {category}”. The tab that opens first is the one the shopper came from — a brand page opens the brand, a category page the category. Both lists are on the page for Google either way.',
            ['tabs' => 'Two tabs — this brand / this category', 'one' => 'One row, brand and category mixed (as before)']],

        'first' => ['select', 'Block 1 · Tab that opens first', 'brand',
            'For a shopper who did not come from a brand or category page — from search, Google, a link or the homepage.',
            ['brand' => 'More from the brand', 'category' => 'More from the category']],

        'routine_on' => ['bool', 'Block 2 · Show “Complete your routine”', true,
            'A grid of the next steps of a routine — cleanser, toner, serum, moisturiser, sunscreen — from the shelves set in Product page → Buy these together → category pairs. In stock first, and products sharing a tag (a skin concern) with this one first. Never repeats a product shown above it.'],

        'routine_count' => ['range', 'Block 2 · How many products', 10,
            'Ten fills two rows on a laptop.',
            ['min' => 4, 'max' => 12, 'step' => 1, 'unit' => '']],

        'routine_title' => ['text', 'Block 2 · Heading', '',
            'Leave empty for the standard heading, “Complete your routine”.'],

        'routine_title_ar' => ['text', 'Block 2 · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'recent_on' => ['bool', 'Block 3 · Show “Continue shopping”', true,
            'A carousel of what this shopper looked at recently, then the shop’s best sellers. A first-time visitor and Google see best sellers.'],

        'recent_count' => ['range', 'Block 3 · How many products', 10,
            'The most the carousel will hold.',
            ['min' => 4, 'max' => 12, 'step' => 1, 'unit' => '']],

        'recent_title' => ['text', 'Block 3 · Heading', '',
            'Leave empty for the standard heading, “Continue shopping”.'],

        'recent_title_ar' => ['text', 'Block 3 · Heading — Arabic', '',
            'Leave empty for the standard Arabic heading.'],

        'order' => ['select', 'Order of the three blocks', '123',
            'Top to bottom, at the foot of the product page.',
            self::ORDERS],
    ];

    /** The six orders of the three blocks. A select stores one of these or the default. */
    public const ORDERS = [
        '123' => '1 Tabs · 2 Routine grid · 3 Continue shopping',
        '132' => '1 Tabs · 3 Continue shopping · 2 Routine grid',
        '213' => '2 Routine grid · 1 Tabs · 3 Continue shopping',
        '231' => '2 Routine grid · 3 Continue shopping · 1 Tabs',
        '312' => '3 Continue shopping · 1 Tabs · 2 Routine grid',
        '321' => '3 Continue shopping · 2 Routine grid · 1 Tabs',
    ];

    /** Same shape as ProductLayout::TABS — the console draws it the same way. */
    public const TABS = [
        'ymal' => ['You may also like',
            'The three blocks at the foot of every product page: 1 the tabs (this brand / this category), 2 “Complete your routine”, 3 “Continue shopping” — where their products come from, how many, and how they move.',
            ['enabled', 'layout', 'first', 'rule', 'mix', 'fill', 'count', 'hide_oos', 'per_desktop', 'per_phone', 'arrows_m',
                'autoplay', 'autoplay_s', 'title', 'title_ar', 'eyebrow', 'eyebrow_ar',
                'routine_on', 'routine_count', 'routine_title', 'routine_title_ar',
                'recent_on', 'recent_count', 'recent_title', 'recent_title_ar', 'order']],
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
            'eyebrow' => $eyebrow !== '' ? $eyebrow : (string) __('store.product.related_eyebrow'),
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
