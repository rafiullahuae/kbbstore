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
            'The most the carousel will hold.',
            ['min' => 4, 'max' => 24, 'step' => 1, 'unit' => '']],

        'hide_oos' => ['bool', 'Hide out-of-stock products', true,
            'Leave sold-out products out of the carousel. Hidden and unpublished products are never shown.'],

        'per_desktop' => ['select', 'Cards in view on a laptop', '5',
            'How many cards fit across before the arrows take over. 5 is the size the cards already were.',
            ['3' => '3', '4' => '4', '5' => '5', '6' => '6']],

        'per_phone' => ['select', 'Cards in view on a phone', '2',
            'How many cards fit across a phone before a swipe. The next card peeks in at the edge.',
            ['1' => '1', '2' => '2', '3' => '3']],

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
            'Leave empty for the standard line, “Complete your routine”.'],

        'eyebrow_ar' => ['text', 'Small line — Arabic', '',
            'Leave empty for the standard Arabic line.'],
    ];

    /** Same shape as ProductLayout::TABS — the console draws it the same way. */
    public const TABS = [
        'ymal' => ['You may also like',
            'The carousel at the foot of every product page: where its products come from, how many, and how it moves.',
            ['enabled', 'rule', 'mix', 'fill', 'count', 'hide_oos', 'per_desktop', 'per_phone',
                'autoplay', 'autoplay_s', 'title', 'title_ar', 'eyebrow', 'eyebrow_ar']],
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
