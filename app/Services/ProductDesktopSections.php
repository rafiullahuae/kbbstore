<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Appearance → Product page → Desktop sections: the ORDER of the full-width
 * blocks under the laptop page's two columns.                       (Lane RF)
 *
 * The owner, 2 October, after Mobile sections shipped:
 *
 *   "also give option to change position in desktop also fo the sections.
 *    make all those changes carefully without disturbing other things. and
 *    super fast and optimized."
 *
 * ── WHAT MOVES ON A LAPTOP, AND WHAT DOES NOT ───────────────────────────────
 *
 * The laptop page is two columns — the gallery and the buy column, one grid
 * (`.pdp`) — and then four full-width blocks stacked under it, each a direct
 * child of `.pdp-page`:
 *
 *   buytogether  `.kbb-fbt`     Buy these together
 *   details      `.pm-details`  Product details + tabs
 *   reviews      `.sr`          Reviews
 *   related      `.ymal`        You may also like
 *
 * Those four are this list. The two columns stay at the top, and the blocks
 * INSIDE the buy column are not reorderable on a laptop, deliberately: the
 * nine gaps between them (10, 20, 20, 20, 16, 16, 0, 22, 12px, measured at
 * 1280) are margins collapsing THROUGH the section wrappers and the cart form,
 * several of them set by sliders on the Layout and Trust · Spacing tabs, plus
 * the Ledger's hairline seams and two adjacency rules keyed to DOM neighbours
 * (`.pm-ready:has(+ .pts-del)`). Flex items do not collapse margins, so any
 * reorder there changes every gap in the column at once — the phone page paid
 * for that with an "even gap" rewrite, and the owner's words for the laptop
 * buy column were "for desktop everything is fine".
 *
 * ── HOW IT REACHES THE PAGE: NOTHING AT ALL UNTIL HE MOVES SOMETHING ────────
 *
 * The default order IS today's DOM order, and while the stored order equals
 * it wrapperClass() and wrapperStyle() return '' — the page is byte-for-byte
 * what it was, and no rule in the stylesheet matches. Once he moves a block,
 * `.pdp-page` gains:
 *
 *   classes  `pds-on`, and `pds-ar-<key>` for the block drawn straight after
 *            Reviews (see afterReviews()) — literals from SECTIONS.
 *   style    `--pds-o-<key>:<int>` — a literal name from SECTIONS and an
 *            integer 1–4 this class computed.
 *
 * resources/css/kbb/kbb-product.css reads them inside `@media (min-width:881px)`
 * only — the laptop side of the page's own 880px phone breakpoint — where
 * `.pdp-page.pds-on` becomes a flex column and the four blocks take `order`.
 * The phone never reads any of it; its order is Mobile sections'.
 *
 * ── KEYBOARD AND SCREEN-READER ORDER ────────────────────────────────────────
 *
 * CSS `order` moves the picture, not the document: tabbing and a screen
 * reader still meet the blocks in the template's order. That is the same
 * trade Mobile sections made for the phone (one rendered page serves both
 * widths, so the HTML cannot be in two orders), and it keeps every id, anchor
 * (`#sr`) and landmark where it was.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * One row of `settings`, read through the snapshot SettingsService already
 * holds for the request; layout() is memoised on the instance.
 */
class ProductDesktopSections
{
    /** The stored order: ONE row, a JSON list of section keys. */
    public const ORDER_KEY = 'pdpds_order';

    /**
     * The four blocks, in TODAY's order — which is the default order.
     * key => [label, what it is, Sections-tab module that switches it per device].
     *
     * The keys are Mobile sections' own keys for the same blocks, so one block
     * has one name on both tabs.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const SECTIONS = [
        'buytogether' => ['Buy these together', 'The product plus its matches, a tick on each, one pink button. Settings: the Buy these together tab.', 'fbt'],
        'details' => ['Product details + tabs', 'Description, ingredients, how to use…', 'tabs'],
        'reviews' => ['Reviews', 'Score summary, filters and review cards.', 'reviews'],
        'related' => ['You may also like', 'The carousel of suggested products.', 'related'],
    ];

    /** @var list<string>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** @return list<string> */
    public static function defaultOrder(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * The order, saved or shipped, ALWAYS a complete duplicate-free list.
     *
     * A stored row is re-validated on the way OUT as well as on the way in —
     * `settings` is a table and the live box has a shell. An unknown key is
     * dropped, a repeat is dropped, a missing key is appended in the default
     * order.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->memo ??= self::clean($this->settings->get(self::ORDER_KEY, null));
    }

    /**
     * Lenient: whatever is stored, a complete list comes back.
     *
     * @return list<string>
     */
    public static function clean(mixed $raw): array
    {
        $order = [];

        foreach (is_array($raw) ? $raw : [] as $key) {
            if (is_string($key) && isset(self::SECTIONS[$key]) && ! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        foreach (self::defaultOrder() as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return $order;
    }

    /**
     * Strict: a POSTed order. Returns the clean list, or an error string.
     *
     * An unknown key and a key listed twice are REFUSED (a typo must not
     * report "Saved"); a missing key is appended in the default order, so a
     * screen built before a block existed cannot lose it.
     *
     * @return list<string>|string
     */
    public static function validate(mixed $posted): array|string
    {
        if (! is_array($posted) || ! array_is_list($posted)) {
            return 'The desktop section order must be a list.';
        }

        $order = [];

        foreach ($posted as $key) {
            if (! is_string($key) || ! isset(self::SECTIONS[$key])) {
                return 'Unknown desktop section: '.(is_scalar($key) ? (string) $key : gettype($key)).'.';
            }

            if (in_array($key, $order, true)) {
                return "Desktop section listed twice: {$key}.";
            }

            $order[] = $key;
        }

        return self::clean($order);
    }

    /** @param list<string> $order a list validate() returned */
    public function save(array $order): void
    {
        $this->settings->set(self::ORDER_KEY, self::clean($order));
        $this->memo = null;
    }

    /** Is the page in the order it has always been drawn in? */
    public function isDefault(): bool
    {
        return $this->order() === self::defaultOrder();
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    /**
     * The block drawn straight after Reviews on this page, or null.
     *
     * Reviews is the one block with a bottom margin (sorina-reviews.css's
     * `margin:34px auto 56px`), and Buy these together is the one with a top
     * margin (34px). In the page's normal flow those two COLLAPSE — the gap is
     * the larger, 56px — but flex items do not collapse margins, so with the
     * order switched on the gap would be 90px. The block after Reviews
     * therefore drops its own top margin, which gives back exactly the 56px
     * the page draws today; for the other blocks the top margin is already 0.
     *
     * `$drawn` is which blocks this product actually draws on a laptop (the
     * template knows: Buy these together with no matches, a carousel with no
     * suggestions and a block switched off for laptops are not there), so the
     * block that loses its margin is the one really underneath.
     *
     * @param  array<string, bool>  $drawn
     */
    public function afterReviews(array $drawn): ?string
    {
        $order = $this->order();
        $at = array_search('reviews', $order, true);

        if ($at === false || ! ($drawn['reviews'] ?? true)) {
            return null;
        }

        foreach (array_slice($order, $at + 1) as $key) {
            if ($drawn[$key] ?? true) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Which of the four blocks this product draws on a laptop, from what the
     * product page already holds — the Sections switches and the two lists the
     * controller already built. No query.
     *
     * @param  mixed  $buyTogether  the view's `$buyTogether` (BuyTogether::forProduct())
     * @param  mixed  $alsoLike     the view's `$alsoLike`
     * @return array<string, bool>
     */
    public static function drawn(ProductSections $modules, mixed $buyTogether, mixed $alsoLike): array
    {
        $all = $modules->all();
        $laptop = static fn (string $module): bool => (bool) ($all[$module]['desktop'] ?? true);
        $has = static fn (mixed $list): bool => is_array($list)
            && is_object($list['products'] ?? null)
            && method_exists($list['products'], 'isNotEmpty')
            && $list['products']->isNotEmpty();

        return [
            'buytogether' => $laptop('fbt') && ! $modules->hidden('fbt') && $has($buyTogether),
            // The heading and its eyebrow are drawn whatever the tabs switch says.
            'details' => true,
            'reviews' => ! $modules->hidden('reviews'),
            'related' => $laptop('related') && $has($alsoLike),
        ];
    }

    /**
     * Extra classes for `.pdp-page`, with their leading space — or '' while
     * the order is the default, so the wrapper is byte-identical to today.
     *
     * @param  array<string, bool>  $drawn
     */
    public function wrapperClass(array $drawn = []): string
    {
        if ($this->isDefault()) {
            return '';
        }

        $after = $this->afterReviews($drawn);

        return ' pds-on'.($after === null ? '' : ' pds-ar-'.$after);
    }

    /**
     * Extra custom properties for `.pdp-page`, with their leading `;` — or ''
     * while the order is the default. Literal names, integer values.
     */
    public function wrapperStyle(): string
    {
        if ($this->isDefault()) {
            return '';
        }

        $out = '';

        foreach ($this->order() as $i => $key) {
            $out .= ';--pds-o-'.$key.':'.($i + 1);
        }

        return $out;
    }

    /**
     * The screen's payload: the list in the saved order, each row with the
     * laptop switch the Sections tab holds for it.
     *
     * @return array{list: list<array<string, mixed>>, defaults: list<string>}
     */
    public function payload(): array
    {
        $modules = app(ProductSections::class)->all();
        $list = [];

        foreach ($this->order() as $key) {
            [$label, $desc, $module] = self::SECTIONS[$key];
            $list[] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'desktop' => (bool) ($modules[$module]['desktop'] ?? true),
            ];
        }

        return ['list' => $list, 'defaults' => self::defaultOrder()];
    }
}
