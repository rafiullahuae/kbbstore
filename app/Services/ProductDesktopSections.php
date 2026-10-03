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
 *
 * ══ LANE RG: ON/OFF FOR EVERY LAPTOP SECTION, AND THE BUY COLUMN'S ORDER ═══
 *
 * The owner, 3 October:
 *
 *   "also give control to hide unhide any section on desktop too, like
 *    bundles i want to hide on desktop too."
 *   "in fact there is control but i turned it off, still it's showing bundle
 *    section on desktop."
 *   "and also controls for changing positions of the sections on desktop too."
 *
 * ── THE BUNDLES BUG ─────────────────────────────────────────────────────────
 *
 * Sections → "Options / bundles" → Desktop wrote `product_sections.options.
 * desktop = false`, and the template read it in exactly ONE place: the class
 * on a VARIABLE product's `.variants` list. A simple product's quantity
 * bundles ("Choose your option · 1 unit / 2-pack / 3-pack") are drawn by the
 * `@elseif ($bundles)` branch, whose `.variants` never carried the class, and
 * the "Choose your option" label above either list never did. So on his
 * simple product the switch did nothing at any width, and on a variable one
 * it left the heading standing over nothing. And `.d-off` itself only hides
 * from 901px (kbb.css, the homepage's breakpoint), so 881–900px — already the
 * laptop layout of this page — showed every module switched off for laptops.
 *
 * Fixed here by switching SECTIONS, not fragments: the wrapper carries
 * `pd-off-<section>` for every section off on a laptop, and kbb-product.css
 * hides the section's own box inside `min-width:881px` — the same shape and
 * the same breakpoint as Mobile sections' `pm-off-<section>` on the phone.
 *
 * ── ONE STORED VALUE PER SWITCH ─────────────────────────────────────────────
 *
 * Eight sections ARE a Sections-tab module (ProductMobileSections::MODULE:
 * short, bundles→options, trust, paychips, buytogether→fbt, details→tabs,
 * reviews, related). Their laptop switch is that module's `desktop` value in
 * `product_sections` — read from it and written into it, never copied. The
 * other seven had no laptop switch at all; theirs is the one list
 * `pdpds_off` (keys switched OFF, so a shop that never saved is all on).
 * The photograph is not in either list: it is the laptop page's left column,
 * and "off" would leave half the page empty.
 *
 * ── THE BUY COLUMN, REORDERED ───────────────────────────────────────────────
 *
 * RF's header above explains why the column was left alone: its gaps are
 * margins collapsing through the wrappers. That stays true in the DEFAULT
 * order — nothing below prints a byte until he moves a block. Once he does,
 * `.pdp-page` gains `pdsb-on pdsb-f-<first>` and `--pdsb-o-<key>:<int>`, and
 * inside `min-width:881px` the buy column becomes a flex column in which every
 * block's outer margins are zeroed and each block carries ONE explicit gap
 * above it: the slider that sets that same gap today (Spacing · Buy column,
 * Trust · Spacing). So the default order drawn this way measures identical,
 * and any other order spaces each block by its own slider. The first block
 * drawn has no gap above it.
 *
 * ══ LANE RI: "BUY THESE TOGETHER" CAN MOVE INTO THE RIGHT COLUMN ═══════════
 *
 * The owner, 3 October, from a laptop at ~1900px:
 *
 *   "ONLY IN DESKTOP: allow me option to bring the buy together section to
 *    the right collumn, by drag n drop."
 *
 * On a wide laptop the buy column ends ~300px above the bottom of the photo.
 * `buytogether` is the ONE key allowed in both lists (MOVABLE). It lives in
 * exactly one of them: in `pdpds_buy_order` when he has dragged it into the
 * buy column, otherwise in `pdpds_order` (its default home). order() drops it
 * from the full-width list whenever the buy column holds it, so a stored row
 * edited from the shell cannot draw it twice; validatePlacement() refuses a
 * POST that lists it in both.
 *
 * WHERE IT IS DRAWN. Placed in the buy column, the template prints the ONE
 * `.kbb-fbt` element as the last child of `.buybox` (outside the cart form —
 * the block's checkboxes must never submit with Add to cart) instead of after
 * `.pdp`. Its key in the buy order makes that order non-default, so RG's
 * `.pdsb-on` flex column is printed and `--pdsb-o-buytogether` puts it at the
 * position he chose, with its own slider above it (Layout → Spacing · Buy
 * column → "Space above Buy these together · right column"). On a phone
 * `.buybox` is `display:contents`, so the element is still a flex item of
 * `.pdp-page` and takes Mobile sections' `--pm-o-buytogether` exactly as
 * before — and the DOM sequence is the same too: it is the element straight
 * after the payment chips either way.
 */
class ProductDesktopSections
{
    /**
     * Lane RI: the one full-width block that may also sit in the buy column.
     */
    public const MOVABLE = 'buytogether';
    /** Lane RG: the buy column's laptop order, ONE row, a JSON list of keys. */
    public const BUY_ORDER_KEY = 'pdpds_buy_order';

    /** Lane RG: the sections switched OFF for laptops that no Sections-tab module owns. */
    public const OFF_KEY = 'pdpds_off';

    /**
     * Lane RG: the blocks of the laptop buy column, in TODAY's order (the DOM
     * order, which is the default). Keys are Mobile sections' own.
     * key => [label, what it is].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const BUY = [
        'title' => ['Title', 'The brand line and the product name, with the share icon.'],
        'price' => ['Price row', 'Struck-through price, live price, discount capsule and the rating.'],
        'short' => ['Short description', 'The blurb with “Read more”. On a set it stays under “What is in this set”.'],
        'paylater' => ['Tabby & Tamara', 'Two small cards: monthly payments with tabby, instalments with tamara. Their wording is on Mobile sections.'],
        'bundles' => ['Bundle section', '“Choose your option” — the quantity bundles, a variable product’s options, or a set’s “What is in this set”.'],
        'ready' => ['Ready to ship', 'The stock line and the dispatch countdown.'],
        'delivery' => ['Delivery box', 'The yellow delivery box.'],
        'cart' => ['Quantity + Add to cart', 'The quantity stepper and the button (and Buy it now, and “tell me when it is back”).'],
        'auth' => ['Authenticity row', '“Authenticity Guaranteed” with its slide-open explanation.'],
        'trust' => ['Trust lines', '100% authentic · delivery · pay-later rows.'],
        'paychips' => ['Payment chips', 'Tabby · Tamara · Visa · Mastercard · COD chips.'],
    ];

    /** @var list<string>|null */
    private ?array $buyMemo = null;

    /** @var array<string, bool>|null */
    private ?array $laptopMemo = null;

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
        if ($this->memo !== null) {
            return $this->memo;
        }

        $order = self::clean($this->settings->get(self::ORDER_KEY, null));

        // Lane RI: in the buy column, so not in this list — whatever the row says.
        return $this->memo = $this->buyTogetherRight()
            ? array_values(array_diff($order, [self::MOVABLE]))
            : $order;
    }

    /** Lane RI: has he dragged Buy these together into the buy column? */
    public function buyTogetherRight(): bool
    {
        return in_array(self::MOVABLE, $this->buyOrder(), true);
    }

    /**
     * Lane RI: the full-width list's DEFAULT for where Buy these together is
     * now — the DOM order of the blocks this list holds, so moving Buy these
     * together across alone prints no `pds-on`: the three blocks left under
     * the columns are still drawn in the page's own normal flow.
     *
     * @return list<string>
     */
    private function underDefault(): array
    {
        return $this->buyTogetherRight()
            ? array_values(array_diff(self::defaultOrder(), [self::MOVABLE]))
            : self::defaultOrder();
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

    /* ═══════════════════ Lane RG: the buy column's order ═══════════════════ */

    /** @return list<string> */
    public static function defaultBuyOrder(): array
    {
        return array_keys(self::BUY);
    }

    /** @return list<string> */
    public function buyOrder(): array
    {
        return $this->buyMemo ??= self::cleanList($this->settings->get(self::BUY_ORDER_KEY, null), self::defaultBuyOrder(), [self::MOVABLE]);
    }

    public function isBuyDefault(): bool
    {
        return $this->buyOrder() === self::defaultBuyOrder();
    }

    /**
     * Strict, like validate(): an unknown key or a repeat is refused, a
     * missing key appended in the default order.
     *
     * @return list<string>|string
     */
    public static function validateBuy(mixed $posted): array|string
    {
        if (! is_array($posted) || ! array_is_list($posted)) {
            return 'The buy column order must be a list.';
        }

        $order = [];

        foreach ($posted as $key) {
            // Lane RI: Buy these together is the one full-width block allowed here.
            if (! is_string($key) || (! isset(self::BUY[$key]) && $key !== self::MOVABLE)) {
                return 'Unknown buy column block: '.(is_scalar($key) ? (string) $key : gettype($key)).'.';
            }

            if (in_array($key, $order, true)) {
                return "Buy column block listed twice: {$key}.";
            }

            $order[] = $key;
        }

        return self::cleanList($order, self::defaultBuyOrder(), [self::MOVABLE]);
    }

    /** @param list<string> $order a list validateBuy() returned */
    public function saveBuy(array $order): void
    {
        $this->settings->set(self::BUY_ORDER_KEY, self::cleanList($order, self::defaultBuyOrder(), [self::MOVABLE]));
        $this->buyMemo = null;
        $this->memo = null;
    }

    /**
     * Lane RI: the two lists of one POST, checked TOGETHER for the one key
     * both may hold. Each list is validated by its own rule first (validate(),
     * validateBuy() — an unknown key or a repeat refused). Then:
     *
     *   · Buy these together in BOTH lists is refused — it is one element.
     *   · A list not in this POST is the stored one, so a POST of one list
     *     cannot put the block in the other list's place as well.
     *   · In NEITHER list it goes home, under the columns (validate()'s own
     *     "a missing key is appended" rule, which is RF's).
     *
     * Returns [order|null, buy|null] — null for a list not posted — or an
     * error string.
     *
     * @return array{0: list<string>|null, 1: list<string>|null}|string
     */
    public function validatePlacement(bool $hasOrder, mixed $order, bool $hasBuy, mixed $buy): array|string
    {
        $o = null;
        $b = null;

        if ($hasBuy) {
            $b = self::validateBuy($buy);

            if (is_string($b)) {
                return $b;
            }
        }

        if ($hasOrder) {
            // Strict on the keys it was SENT: a missing Buy these together is
            // not "listed", so it is judged by where the buy list puts it.
            $sentUnder = is_array($order) && array_is_list($order) && in_array(self::MOVABLE, $order, true);
            $o = self::validate($order);

            if (is_string($o)) {
                return $o;
            }
        } else {
            // Not posted: the stored list keeps whatever the buy list leaves it.
            $sentUnder = false;
        }

        $inBuy = in_array(self::MOVABLE, $b ?? $this->buyOrder(), true);

        if ($inBuy && $sentUnder) {
            return 'Buy these together can sit in one place only: the buy column or under the two columns, not both.';
        }

        return [$o, $b];
    }

    /**
     * @param  list<string>  $defaults  every key, in the default order — appended when missing
     * @param  list<string>  $optional  keys allowed too, but never appended (Lane RI)
     * @return list<string>
     */
    private static function cleanList(mixed $raw, array $defaults, array $optional = []): array
    {
        $order = [];

        foreach (is_array($raw) ? $raw : [] as $key) {
            if (is_string($key) && (in_array($key, $defaults, true) || in_array($key, $optional, true)) && ! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        foreach ($defaults as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return $order;
    }

    /* ═══════════════ Lane RG: one laptop switch per section ════════════════ */

    /**
     * Every section that has a laptop switch: the buy column's eleven and the
     * four full-width blocks, in that order.
     *
     * @return list<string>
     */
    public static function switchable(): array
    {
        return [...array_keys(self::BUY), ...array_keys(self::SECTIONS)];
    }

    /** The Sections-tab module whose `desktop` value IS this section's switch, or null. */
    public static function moduleOf(string $section): ?string
    {
        $module = array_search($section, ProductMobileSections::MODULE, true);

        return is_string($module) ? $module : null;
    }

    /**
     * section => on a laptop?, for every switchable section. A module-owned
     * section reads `product_sections` through ProductSections (its desktop
     * default included); the rest are on unless listed in `pdpds_off`.
     *
     * @return array<string, bool>
     */
    public function laptop(): array
    {
        if ($this->laptopMemo !== null) {
            return $this->laptopMemo;
        }

        $modules = app(ProductSections::class)->all();
        $off = $this->settings->get(self::OFF_KEY, null);
        $off = is_array($off) ? $off : [];
        $out = [];

        foreach (self::switchable() as $key) {
            $module = self::moduleOf($key);
            $out[$key] = $module !== null
                ? (bool) ($modules[$module]['desktop'] ?? true)
                : ! in_array($key, $off, true);
        }

        return $this->laptopMemo = $out;
    }

    /**
     * Strict: a POSTed `{section: bool}` map. An unknown key or a non-boolean
     * is refused.
     *
     * @return array<string, bool>|string
     */
    public static function validateLaptop(mixed $posted): array|string
    {
        if (! is_array($posted) || ($posted !== [] && array_is_list($posted))) {
            return 'The laptop switches must be a map of section to on/off.';
        }

        $all = self::switchable();
        $clean = [];

        foreach ($posted as $key => $on) {
            if (! is_string($key) || ! in_array($key, $all, true)) {
                return 'Unknown desktop section: '.(is_scalar($key) ? (string) $key : gettype($key)).'.';
            }

            if (! is_bool($on) && ! in_array($on, [0, 1, '0', '1'], true)) {
                return "The laptop switch for {$key} must be on or off.";
            }

            $clean[$key] = (bool) $on;
        }

        return $clean;
    }

    /**
     * Write each switch where it LIVES: a module-owned section into its
     * `product_sections` row's `desktop` (the row's `mobile` untouched — the
     * same targeted write the Buy these together tab makes for `fbt`), the
     * rest into `pdpds_off`.
     *
     * @param  array<string, bool>  $switches  a map validateLaptop() returned
     */
    public function saveLaptop(array $switches): void
    {
        $map = $this->settings->get('product_sections');
        $map = is_array($map) ? $map : [];
        $mapChanged = false;

        $off = $this->settings->get(self::OFF_KEY, null);
        $off = array_values(array_filter(is_array($off) ? $off : [], static fn ($k) => is_string($k) && isset(self::BUY[$k]) && self::moduleOf($k) === null));
        $offChanged = false;

        foreach ($switches as $key => $on) {
            $module = self::moduleOf($key);

            if ($module !== null) {
                $row = is_array($map[$module] ?? null) ? $map[$module] : [];
                $default = (bool) (ProductSections::REGISTRY[$module][2] ?? true);
                $map[$module] = ['desktop' => $on, 'mobile' => (bool) ($row['mobile'] ?? $default)];
                $mapChanged = true;

                continue;
            }

            $offChanged = true;
            $off = array_values(array_diff($off, [$key]));

            if (! $on) {
                $off[] = $key;
            }
        }

        if ($mapChanged) {
            $this->settings->set('product_sections', $map);
        }

        if ($offChanged) {
            // Stored in the buy column's own order, so the row reads the same however it was clicked.
            $this->settings->set(self::OFF_KEY, array_values(array_intersect(self::defaultBuyOrder(), $off)));
        }

        $this->laptopMemo = null;
    }

    /** Is the page in the order it has always been drawn in? */
    public function isDefault(): bool
    {
        // Lane RI: against the blocks this list holds (see underDefault()).
        return $this->order() === $this->underDefault();
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
            /* Lane RG: the section's laptop switch now hides the WHOLE section
               (`pd-off-details`, `pd-off-reviews`), heading included, so a block
               switched off for laptops is not "drawn" for the margin rule. */
            'details' => $laptop('tabs'),
            'reviews' => $laptop('reviews') && ! $modules->hidden('reviews'),
            'related' => $laptop('related') && $has($alsoLike),
        ];
    }

    /**
     * Extra classes for `.pdp-page`, with their leading space — or '' while
     * the order is the default, so the wrapper is byte-identical to today.
     *
     * @param  array<string, bool>  $drawn
     */
    public function wrapperClass(array $drawn = [], array $buyDrawn = []): string
    {
        $out = '';

        // Lane RG: one class per section switched off for laptops. Literal
        // names from BUY / SECTIONS keys; none while everything is on.
        foreach ($this->laptop() as $key => $on) {
            if (! $on) {
                $out .= ' pd-off-'.$key;
            }
        }

        if (! $this->isDefault()) {
            $after = $this->afterReviews($drawn);
            $out .= ' pds-on'.($after === null ? '' : ' pds-ar-'.$after);
        }

        if (! $this->isBuyDefault()) {
            $first = $this->firstBuy($buyDrawn);
            $out .= ' pdsb-on'.($first === null ? '' : ' pdsb-f-'.$first);
        }

        return $out;
    }

    /**
     * Lane RG: the buy-column block drawn FIRST on this laptop page, which is
     * the one with no gap above it. A block switched off for laptops is
     * skipped, and so is one this product does not draw at all — `$buyDrawn`
     * is the template's answer for the three that depend on the product (a
     * blurb, the pay-later cards, an options list); every other block is
     * always drawn.
     *
     * @param  array<string, bool>  $buyDrawn
     */
    public function firstBuy(array $buyDrawn = []): ?string
    {
        $laptop = $this->laptop();

        foreach ($this->buyOrder() as $key) {
            if (($laptop[$key] ?? true) && ($buyDrawn[$key] ?? true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Extra custom properties for `.pdp-page`, with their leading `;` — or ''
     * while the order is the default. Literal names, integer values.
     */
    public function wrapperStyle(): string
    {
        $out = '';

        if (! $this->isDefault()) {
            foreach ($this->order() as $i => $key) {
                $out .= ';--pds-o-'.$key.':'.($i + 1);
            }
        }

        // Lane RG: the buy column's positions, only once he has moved one.
        if (! $this->isBuyDefault()) {
            foreach ($this->buyOrder() as $i => $key) {
                $out .= ';--pdsb-o-'.$key.':'.($i + 1);
            }
        }

        return $out;
    }

    /**
     * The screen's payload: both lists in their saved order, each row with
     * its ONE laptop switch (read from where it lives — see laptop()) and,
     * for a module-owned row, the Sections-tab module it is.
     *
     * @return array{list: list<array<string, mixed>>, defaults: list<string>, buy: list<array<string, mixed>>, buy_defaults: list<string>}
     */
    public function payload(): array
    {
        $laptop = $this->laptop();
        $row = static fn (string $key, string $label, string $desc): array => [
            'key' => $key,
            'label' => $label,
            'description' => $desc,
            'desktop' => $laptop[$key] ?? true,
            'module' => self::moduleOf($key),
        ];

        $list = [];

        foreach ($this->order() as $key) {
            [$label, $desc] = self::SECTIONS[$key];
            $list[] = $row($key, $label, $desc);
        }

        $buy = [];

        foreach ($this->buyOrder() as $key) {
            // Lane RI: Buy these together keeps its own label wherever it sits.
            [$label, $desc] = self::BUY[$key] ?? self::SECTIONS[$key];
            $buy[] = $row($key, $label, $desc);
        }

        return ['list' => $list, 'defaults' => self::defaultOrder(), 'buy' => $buy, 'buy_defaults' => self::defaultBuyOrder()];
    }
}
