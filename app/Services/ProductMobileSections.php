<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Appearance → Product page → Mobile sections: the phone product page as an
 * ordered list of sections, each one switchable, with the space between them.
 *                                                                  (Lane QA)
 *
 * The owner, 2 October:
 *
 *   "i want things need to work as sections. image + gallery, Title, short
 *    description, then price row (cut price + actual price + discount
 *    capsule) + on right side rating (4.9 and bar, remove count) but also give
 *    option display reviews in seperate row incase we don't like on right side
 *    of the pricing, then bundle section, then ready to ship row, then
 *    quantity + add to cart section, then delivery yellow box, then
 *    authenticity row, then Buy together box (keep turned off by default),
 *    remove the 100% authentic 3 rows completely, remove the THE DETAILS sub
 *    heading completely. then Product Details section along with tabs, and
 *    then reviews section, and then you may also like section. [...] and give
 *    functionality to drag an drop the positioning changing / sorting, and
 *    ON/OFF anything. THIS message changes is only for MOBILE. for desktop
 *    everything is fine. also i want one more section. Tabby and Tamara."
 *
 * and, the same day: "also make sure that we will have control for spacing
 * between the sections to adjust. by default keep same space between, i mean
 * good enough."
 *
 * ── ▲ WHAT HE ASKED FOR SHIPS ON (CLAUDE.md, the 30 September reversal) ─────
 *
 * His order is the default order. Buy together ships OFF ("keep turned off by
 * default"), the three trust rows ship OFF on the phone ("remove ... 100%
 * authentic 3 rows completely"), "THE DETAILS" ships hidden on the phone, the
 * rating sits beside the price with no review count, and Tabby & Tamara ship
 * ON directly after the price row (the one placement he did not name; it is
 * the place a pay-later line reads as part of the price). Every one of those
 * is a control he can take back.
 *
 * ── HOW IT REACHES THE PAGE: CSS ONLY, AND ONLY VALIDATED INTEGERS ─────────
 *
 * One rendered page serves both widths, so nothing here decides what is in the
 * HTML. The wrapper `.pdp-page` carries:
 *
 *   classes  `pm-off-<key>` for a section switched off, and one class per
 *            rating placement — every class name a literal from this file.
 *   style    `--pm-gap:<int>px`, `--pm-o-<key>:<int>` and `--pm-dm-<key>:<int>px`
 *            — every name a literal from SECTIONS, every value an integer this
 *            class clamped.
 *
 * resources/css/kbb/kbb-product.css reads them inside its own phone breakpoint
 * (max-width:880px), so a laptop never sees an `order` or a `display:none`
 * from this screen. No JavaScript measures anything (rule 4).
 *
 * ── ONE SOURCE OF TRUTH PER SECTION PER DEVICE ──────────────────────────────
 *
 * Appearance → Product page → Sections has a Desktop and a Mobile switch per
 * module. Eight of those modules ARE one of these sections (MODULE below):
 * their Mobile switch is now THIS screen's switch, read and written here, and
 * ProductSections::all() answers it for them — so the two tabs cannot
 * disagree because they are reading one value. The Sections tab draws those
 * eight Mobile switches as a pointer to this tab instead of a toggle. The
 * modules that are a PART of a section (the rating capsule, the VAT line, the
 * dispatch countdown, the quantity stepper, Buy it now…) keep their per-device
 * switches there: a section switched off hides everything in it, and a part
 * switched off hides that part — nested, never two switches for one thing.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through the snapshot SettingsService
 * already holds for the request; all() is memoised on the instance.
 */
class ProductMobileSections
{
    /** Every key is stored as `settings.key` = PREFIX . <key>. */
    public const PREFIX = 'pdpms_';

    /** The structural half — order, switches, per-section spacing — is ONE row. */
    public const LAYOUT_KEY = 'pdpms_layout';

    /**
     * The sections, in HIS order. key => [label, what it is, default ON on a phone].
     *
     * `trust` and `paychips` are not in his list by name: `trust` is "the
     * 100% authentic 3 rows" he asked to remove (so it is here, OFF), and the
     * payment chips row under them is a row he did not mention — ON, in the
     * place it already sat, after the authenticity row.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    public const SECTIONS = [
        'gallery' => ['Image + gallery', 'The photograph and its thumbnails.', true],
        'title' => ['Title', 'The brand line and the product name, with the share icon on the right.', true],
        'short' => ['Short description', 'The blurb with “Read more”. On a set it stays under “What is in this set”.', true],
        'price' => ['Price row', 'Struck-through price, live price and the discount capsule, with the rating on the right.', true],
        'paylater' => ['Tabby & Tamara', 'Two small cards side by side: monthly payments with tabby, instalments with tamara.', true],
        'bundles' => ['Bundle section', '“Choose your option” — the bundles, a variable product’s options, or on a set “What is in this set”.', true],
        'ready' => ['Ready to ship', 'The stock line and the dispatch countdown.', true],
        'cart' => ['Quantity + Add to cart', 'The quantity stepper and the button (and Buy it now, and “tell me when it is back” on a sold-out product).', true],
        'delivery' => ['Delivery box', 'The yellow delivery box.', true],
        'auth' => ['Authenticity row', '“Authenticity Guaranteed” with its slide-open explanation.', true],
        'trust' => ['100% authentic · delivery · pay-later rows', 'The three small trust lines. Off on phones, as asked; the laptop keeps them.', false],
        'paychips' => ['Payment chips', 'Tabby · Tamara · Visa · Mastercard · COD chips.', true],
        'buytogether' => ['Buy these together', 'The product plus its matches with a tick on each, and one pink “Buy 4 items together” button. Settings: the Buy these together tab.', false],
        'details' => ['Product details + tabs', 'Description, ingredients, how to use…', true],
        'reviews' => ['Reviews', 'Score summary, filters and review cards.', true],
        'related' => ['You may also like', 'The carousel at the foot of the page.', true],
    ];

    /**
     * The Sections-tab modules that ARE one of these sections. Their Mobile
     * switch is this screen's; ProductSections::all() reads it from here.
     *
     * @var array<string, string> module => section
     */
    public const MODULE = [
        'short' => 'short',
        'options' => 'bundles',
        'trust' => 'trust',
        'paychips' => 'paychips',
        'fbt' => 'buytogether',
        'tabs' => 'details',
        'reviews' => 'reviews',
        'related' => 'related',
    ];

    /**
     * Sections whose default ignores an older Sections-tab Mobile value: he
     * named these two, so a switch saved before he asked does not get to
     * overrule what he asked for. Every other mapped section inherits the
     * Mobile switch he had already saved, so nothing he chose there moves.
     */
    private const ASKED_OFF = ['trust', 'buytogether'];

    /** The bounds of every spacing value on this screen, in px. */
    public const SPACE_MIN = 0;

    public const SPACE_MAX = 48;

    /**
     * The shipped per-section spacing: TWO NAMED EXCEPTIONS to the even gap,
     * and every other section blank (= the shared value).
     *
     *   short  the blurb reads as the name's subline — it sits tighter under
     *          the title than one section does under another.
     *   cart   the stock line and the button are one decision — Add to cart
     *          sits tighter under "In stock · ready to ship".
     *
     * Both are rows on the screen with their number in the box, so he can see
     * them and clear them; neither is a margin left over somewhere.
     *
     * @var array<string, int>
     */
    public const SPACE_DEFAULTS = [
        'short' => 10,
        'cart' => 12,
    ];

    /** key => [type, label, default, help, options] — the scalar options, on ModuleSchema. */
    public const SCHEMA = [
        'gap' => ['range', 'Space between sections · phone', 18,
            'One even gap between every two sections on a phone. A section can carry its own space above it in the list; blank there means this number.',
            ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'rate_m' => ['select', 'Rating · phone', 'beside',
            'Where the score and its bar sit in the price row on a phone.',
            ['beside' => 'Beside the price, on the right', 'row' => 'In its own row under the price', 'hidden' => 'Hidden']],
        'rate_d' => ['select', 'Rating · laptop', 'beside',
            'Where the score and its bar sit in the price row on a laptop.',
            ['beside' => 'Beside the price, on the right', 'row' => 'In its own row under the price']],
        'rate_count' => ['bool', 'Show the review count beside the rating', false,
            'Off, as asked: the price row shows the score and the bar only. On puts “5 reviews” back after the bar, at both widths.'],
        'details_head' => ['bool', 'Show “The details” over Product details · phone', false,
            'The small pink line above the “Product details” heading. Off on phones, as asked; the laptop keeps it.'],
        'tabby_on' => ['bool', 'Show the tabby card', true,
            'Also hidden while the tabby mark is switched off under Appearance → Cart page → Trust row, so this card never advertises a method the shop has turned off.'],
        'tabby_text' => ['text', 'tabby card text', 'Split your purchase into monthly payments', 'Plain text.'],
        'tamara_on' => ['bool', 'Show the tamara card', true,
            'Also hidden while the tamara mark is switched off under Appearance → Cart page → Trust row.'],
        'tamara_text' => ['text', 'tamara card text', 'Installments up to 6 months, no late fees!', 'Plain text.'],
    ];

    /** One tab, ModuleSchema::tabs()' shape. The list itself is drawn by the screen. */
    public const TABS = [
        'msections' => ['Mobile sections',
            'The options under the list: the even gap, where the rating sits, the review count, “The details”, and the two pay-later cards.',
            ['gap', 'rate_m', 'rate_d', 'rate_count', 'details_head', 'tabby_on', 'tabby_text', 'tamara_on', 'tamara_text']],
    ];

    public const POLICY = [
        'max' => 120,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /** @var array{order: list<string>, on: array<string, bool>, space: array<string, int|null>}|null */
    private ?array $layoutMemo = null;

    public function __construct(private SettingsService $settings) {}

    /* ═══════════════════════ the scalar options ════════════════════════════ */

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    /** @return array<string, mixed> */
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

        return $this->memo = $out;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $f) => $f['default'], self::fields());
    }

    /** @param array<string, mixed> $values */
    public function saveOptions(array $values): void
    {
        $fields = self::fields();

        foreach ($values as $key => $value) {
            if (isset($fields[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }

        $this->memo = null;
    }

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(self::fields()[$key], $value);
    }

    /** One of the select's own keys, or its default. Rule 5's second lock. */
    public function choice(string $key): string
    {
        $f = self::fields()[$key];
        $v = (string) ($this->all()[$key] ?? '');

        return is_array($f['options']) && array_key_exists($v, $f['options']) ? $v : (string) $f['default'];
    }

    public function on(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    /**
     * A card's text, or its keyed default in the page's own language — the
     * ProductTrustShare::text() rule: while the value is still exactly the
     * shipped English the InterfaceStrings key prints, so /ar gets Arabic.
     */
    public function text(string $key): string
    {
        $value = (string) ($this->all()[$key] ?? '');
        $keyed = [
            'tabby_text' => 'store.product.paylater_tabby',
            'tamara_text' => 'store.product.paylater_tamara',
        ];

        if (isset($keyed[$key]) && $value === (string) self::fields()[$key]['default']) {
            return (string) __($keyed[$key]);
        }

        return $value;
    }

    /* ═══════════════════════ the structural half ═══════════════════════════ */

    /**
     * Order, switches and per-section spacing, saved or shipped.
     *
     * @return array{order: list<string>, on: array<string, bool>, space: array<string, int|null>}
     */
    public function layout(): array
    {
        if ($this->layoutMemo !== null) {
            return $this->layoutMemo;
        }

        $saved = $this->settings->get(self::LAYOUT_KEY, null);
        $saved = is_array($saved) ? $saved : [];

        /* A stored row is re-validated on the way OUT as well as on the way
           in: `settings` is a table and the live box has a shell. A hand-made
           row with a key twice, a key nobody knows or a missing key comes back
           as a complete, duplicate-free list in the default order. */
        $order = [];

        foreach (is_array($saved['order'] ?? null) ? $saved['order'] : [] as $key) {
            if (is_string($key) && isset(self::SECTIONS[$key]) && ! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        foreach (array_keys(self::SECTIONS) as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $savedOn = is_array($saved['on'] ?? null) ? $saved['on'] : [];
        $old = $this->settings->get('product_sections');
        $old = is_array($old) ? $old : [];
        $moduleOf = array_flip(self::MODULE);

        $on = [];

        foreach (self::SECTIONS as $key => [, , $default]) {
            if (array_key_exists($key, $savedOn)) {
                $on[$key] = self::bool($savedOn[$key]);

                continue;
            }

            // Never saved here: a mapped section inherits the Mobile switch he
            // had already saved on the Sections tab, unless it is one he named.
            $module = $moduleOf[$key] ?? null;

            if ($module !== null && ! in_array($key, self::ASKED_OFF, true)
                && is_array($old[$module] ?? null) && array_key_exists('mobile', $old[$module])) {
                $on[$key] = (bool) $old[$module]['mobile'];

                continue;
            }

            $on[$key] = $key === 'buytogether' ? self::togetherDefault() : $default;
        }

        $savedSpace = is_array($saved['space'] ?? null) ? $saved['space'] : null;
        $space = [];

        foreach (array_keys(self::SECTIONS) as $key) {
            $raw = $savedSpace === null ? (self::SPACE_DEFAULTS[$key] ?? null) : ($savedSpace[$key] ?? null);
            $space[$key] = self::space($raw);
        }

        return $this->layoutMemo = ['order' => $order, 'on' => $on, 'space' => $space];
    }

    /**
     * "Buy these together" on a phone, when this screen has never been saved.
     *                                                                  (Lane RB)
     *
     * It FOLLOWS THE SECTION'S OWN SWITCH (Appearance → Product page → Buy
     * these together → Show). The row used to default OFF because the owner
     * asked for the old Frequently-bought-together box to stay off; on 2
     * October he asked for its replacement on phones and laptops both. Off in
     * the schema means a fresh install and the test suite are unchanged; the
     * migration that switches the section on for his shop switches this row
     * with it. A layout he has saved keeps whatever he saved.
     */
    public static function togetherDefault(): bool
    {
        return (bool) (app(BuyTogetherSettings::class)->all()['on'] ?? false);
    }

    /** @return array{order: list<string>, on: array<string, bool>, space: array<string, int|null>} */
    public static function defaultLayout(): array
    {
        $space = [];

        foreach (array_keys(self::SECTIONS) as $key) {
            $space[$key] = self::SPACE_DEFAULTS[$key] ?? null;
        }

        $on = array_map(static fn (array $s) => $s[2], self::SECTIONS);
        $on['buytogether'] = self::togetherDefault();

        return [
            'order' => array_keys(self::SECTIONS),
            'on' => $on,
            'space' => $space,
        ];
    }

    /**
     * Validate a posted layout. Returns the clean layout, or an error string.
     *
     *   order  a list of section keys. An unknown key is REFUSED, a key twice
     *          is REFUSED ("every key exactly once"), and a key that is
     *          missing is appended in the default order — so a screen built
     *          before a section existed cannot lose it.
     *   on     key => bool; an unknown key is refused; missing keys keep their
     *          current value.
     *   space  key => integer px clamped to SPACE_MIN..SPACE_MAX, or
     *          null / '' for "the shared gap"; an unknown key is refused.
     *
     * @return array{order: list<string>, on: array<string, bool>, space: array<string, int|null>}|string
     */
    public function validate(mixed $posted): array|string
    {
        if (! is_array($posted)) {
            return 'The section list must be an object.';
        }

        $current = $this->layout();
        $order = $current['order'];

        if (array_key_exists('order', $posted)) {
            if (! is_array($posted['order']) || ! array_is_list($posted['order'])) {
                return 'The section order must be a list.';
            }

            $order = [];

            foreach ($posted['order'] as $key) {
                if (! is_string($key) || ! isset(self::SECTIONS[$key])) {
                    return 'Unknown section: '.(is_scalar($key) ? (string) $key : gettype($key)).'.';
                }

                if (in_array($key, $order, true)) {
                    return "Section listed twice: {$key}.";
                }

                $order[] = $key;
            }

            foreach (array_keys(self::SECTIONS) as $key) {
                if (! in_array($key, $order, true)) {
                    $order[] = $key;
                }
            }
        }

        $on = $current['on'];

        if (array_key_exists('on', $posted)) {
            if (! is_array($posted['on'])) {
                return 'The section switches must be an object.';
            }

            foreach ($posted['on'] as $key => $value) {
                if (! isset(self::SECTIONS[$key])) {
                    return "Unknown section: {$key}.";
                }

                $on[$key] = self::bool($value);
            }
        }

        $space = $current['space'];

        if (array_key_exists('space', $posted)) {
            if (! is_array($posted['space'])) {
                return 'The section spacing must be an object.';
            }

            foreach ($posted['space'] as $key => $value) {
                if (! isset(self::SECTIONS[$key])) {
                    return "Unknown section: {$key}.";
                }

                $space[$key] = self::space($value);
            }
        }

        return ['order' => $order, 'on' => $on, 'space' => $space];
    }

    /** @param array{order: list<string>, on: array<string, bool>, space: array<string, int|null>} $clean */
    public function saveLayout(array $clean): void
    {
        $this->settings->set(self::LAYOUT_KEY, $clean);
        $this->layoutMemo = null;
    }

    /** A spacing value: an integer clamped into bounds, or null for "the shared gap". */
    public static function space(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw)) {
            return null;
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return max(self::SPACE_MIN, min(self::SPACE_MAX, (int) round((float) $raw)));
    }

    private static function bool(mixed $v): bool
    {
        if (is_string($v)) {
            return ! in_array(strtolower(trim($v)), ['', '0', 'false', 'off', 'no'], true);
        }

        return (bool) $v;
    }

    /** Whether a section is on, on a phone. */
    public function sectionOn(string $key): bool
    {
        return $this->layout()['on'][$key] ?? false;
    }

    /** The Mobile switch for a Sections-tab module this screen owns, or null. */
    public function moduleMobile(string $module): ?bool
    {
        $section = self::MODULE[$module] ?? null;

        return $section === null ? null : $this->sectionOn($section);
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    /** The space above every section, the shared gap, clamped. */
    public function gap(): int
    {
        return max(self::SPACE_MIN, min(self::SPACE_MAX, (int) ($this->all()['gap'] ?? 18)));
    }

    /**
     * The wrapper's classes: one per section switched off, and one per rating
     * placement. Every name is a literal assembled from SECTIONS keys and a
     * select's own option keys.
     */
    public function wrapperClass(): string
    {
        $out = [];

        foreach ($this->layout()['on'] as $key => $on) {
            if (! $on) {
                $out[] = 'pm-off-'.$key;
            }
        }

        $out[] = 'pm-rate-m-'.$this->choice('rate_m');
        $out[] = 'pd-rate-d-'.$this->choice('rate_d');

        if ($this->on('details_head')) {
            $out[] = 'pm-dhead';
        }

        return implode(' ', $out);
    }

    /**
     * The wrapper's custom properties: the gap, every section's position, and
     * the difference between a section's own space and the shared gap.
     *
     * Integers only. A section's override is ignored for the FIRST section
     * that is switched on, because nothing is above it to be spaced from.
     */
    public function wrapperStyle(): string
    {
        $layout = $this->layout();
        $gap = $this->gap();
        $out = ['--pm-gap:'.$gap.'px'];
        $first = null;

        foreach ($layout['order'] as $i => $key) {
            $out[] = '--pm-o-'.$key.':'.($i + 1);

            if ($first === null && $layout['on'][$key]) {
                $first = $key;
            }
        }

        foreach ($layout['order'] as $key) {
            $space = $layout['space'][$key];

            if ($space !== null && $key !== $first && $space !== $gap) {
                $out[] = '--pm-dm-'.$key.':'.($space - $gap).'px';
            }
        }

        return implode(';', $out);
    }

    /**
     * Which pay-later cards may be drawn: his switch for the card AND the
     * shop's own acceptance mark for that method (Appearance → Cart page →
     * Trust row), read from the settings snapshot — no query.
     *
     * NOT the checkout's own gate, deliberately: that one reads the
     * `payment_providers` table and the gateway's encrypted credentials, which
     * is a query on every product view (StorefrontQueryBudgetTest), and it
     * depends on the basket's total, which a product page does not have.
     *
     * @return list<string> 'tabby' and/or 'tamara', in that order
     */
    public function payLater(): array
    {
        $cart = app(CartPage::class)->all();
        $out = [];

        foreach (['tabby', 'tamara'] as $method) {
            if ($this->on($method.'_on') && (bool) ($cart['pay_'.$method] ?? true)) {
                $out[] = $method;
            }
        }

        return $out;
    }

    /**
     * The screen's payload: the list in the saved order, and the options tab.
     *
     * @return array{list: list<array<string, mixed>>, options: list<array<string, mixed>>, defaults: array<string, mixed>, space: array{min: int, max: int}}
     */
    public function payload(): array
    {
        $layout = $this->layout();
        $moduleOf = array_flip(self::MODULE);
        $list = [];

        foreach ($layout['order'] as $key) {
            [$label, $desc] = self::SECTIONS[$key];
            $list[] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'on' => $layout['on'][$key],
                'space' => $layout['space'][$key],
                'module' => $moduleOf[$key] ?? null,
            ];
        }

        return [
            'list' => $list,
            'options' => ModuleSchema::tabs(self::SCHEMA, self::TABS, $this->all(), self::POLICY),
            'defaults' => self::defaultLayout(),
            'space' => ['min' => self::SPACE_MIN, 'max' => self::SPACE_MAX],
        ];
    }
}
