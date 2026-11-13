<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → Free gifts strip.                     (Lane GS)
 *
 * The owner, 13 November 2026, after live previews of fifteen designs:
 *
 *   "KL Confetti + Holographic sheen is fine, please give me patch. to control
 *    on off, and change position on the product page, keep above tabby tamara
 *    section by default. also give control of colors, effects, overal height,
 *    font size etc."
 *
 * Design KL of docs/plans/product-gift-strip/preview.py, copied exactly: a
 * yellow → coral gradient strip, a gift icon in a soft circle, two lines of
 * words (bold, then lighter), ten confetti pieces falling at the two ends and
 * a thin holographic glint passing every 6 s. CSS only, in kbb-product.css.
 *
 * ── ▲ WHAT HE ASKED FOR SHIPS ON (CLAUDE.md, the 30 September reversal) ────
 *
 * The strip is ON, directly above Tabby & Tamara on the phone and on the
 * laptop, with more space above and below it (24px) than the phone's even gap
 * between sections (18px) — "with little more spacing up n down".
 *
 * ── WHERE IT SITS ───────────────────────────────────────────────────────────
 *
 *   Phone    a row of Mobile sections (`giftstrip`), so it is dragged and
 *            switched like every other phone section, and placed directly
 *            above `paylater` in a layout saved before it existed
 *            (ProductMobileSections::INSERT_BEFORE).
 *   Laptop   desk(): which block of the buy column it is drawn above (or
 *            below Add to cart), or not at all. The template draws the ONE
 *            element at that place in the DOM, so the laptop needs no `order`
 *            and no CSS of its own for it; under a reordered buy column
 *            (`pdsb-on`) a literal class keeps it beside its anchor.
 *
 * ── RULE 5: NOTHING FROM A SETTING REACHES THE PAGE BUT INTEGERS AND HEX ───
 *
 *   style()   `--gs-…:<int>px`, `#RRGGBB` and one `rgba(<int>,<int>,<int>,.55)`
 *             built here from a validated hex — and only the values that
 *             differ from the design, so the default strip carries no style.
 *   classes() literal class names from this file and a select's own keys.
 *   words     through Blade's escaper.
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through the snapshot SettingsService
 * already holds for the request (SettingsRequestMemo); all() is memoised.
 */
class ProductGiftStrip
{
    public const PREFIX = 'pgs_';

    /** The laptop placements, key => label. Every one is a literal class name. */
    public const DESK = [
        'paylater' => 'Above Tabby & Tamara',
        'price' => 'Above the price',
        'cart' => 'Above Add to cart',
        'aftercart' => 'Below Add to cart',
        'off' => 'Not shown on laptops',
    ];

    /** The design's own values, which print no style at all. */
    public const DESIGN = [
        'c1' => '#FFD43B',
        'c2' => '#FF9F6E',
        'c3' => '#FF6F91',
        'ink' => '#3A1230',
        'icon_bg' => '#FFFFFF',
    ];

    public const SCHEMA = [
        'on' => ['bool', 'Show the free gifts strip', true,
            'The whole strip, on every product page. Off removes it from the page entirely. Where it sits on a phone, and whether it shows there, is its row on the Mobile sections tab.'],
        'desk' => ['select', 'Position · laptop', 'paylater',
            'Which part of the buy column it sits above. It shows in this place even when Tabby & Tamara is switched off.',
            self::DESK],
        'line1' => ['text', 'First line (bold)', 'Free gifts with every order', 'Plain text.'],
        'line2' => ['text', 'Second line', 'Limited time offer. Don\'t miss out!', 'Plain text. Leave empty for one line.'],
        'line1_ar' => ['text', 'First line — Arabic', '', 'Leave empty for the standard Arabic: هدايا مجانية مع كل طلب'],
        'line2_ar' => ['text', 'Second line — Arabic', '', 'Leave empty for the standard Arabic: عرض لفترة محدودة. لا تفوّت الفرصة!'],
        'c1' => ['colour', 'Gradient · start', self::DESIGN['c1'], ''],
        'c2' => ['colour', 'Gradient · middle', self::DESIGN['c2'], ''],
        'c3' => ['colour', 'Gradient · end', self::DESIGN['c3'], ''],
        'ink' => ['colour', 'Text and icon colour', self::DESIGN['ink'], ''],
        'icon_bg' => ['colour', 'Icon circle colour', self::DESIGN['icon_bg'], 'Drawn see-through (55%), as in the design.'],
        'icon' => ['bool', 'Show the gift icon', true, ''],
        'confetti' => ['bool', 'Falling confetti', true, 'Ten small pieces falling at the two ends of the strip.'],
        'shine' => ['bool', 'Holographic shine', true, 'A thin glint of light passing across the strip.'],
        'speed' => ['select', 'Shine speed', 'normal', '', [
            'slow' => 'Slow — every 9 s',
            'normal' => 'Normal — every 6 s (as approved)',
            'fast' => 'Fast — every 3.5 s',
        ]],
        'height' => ['type' => 'range', 'label' => 'Height', 'default' => 60, 'help' => 'The strip’s smallest height; longer words make it taller, never cut off.',
            'options' => ['min' => 44, 'max' => 120, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'fs1' => ['type' => 'range', 'label' => 'First line · font size', 'default' => 15.5, 'help' => '',
            'options' => ['min' => 11, 'max' => 24, 'step' => 0.5, 'unit' => 'px'], 'rule' => [self::class, 'halfPx']],
        'fs2' => ['type' => 'range', 'label' => 'Second line · font size', 'default' => 13.5, 'help' => '',
            'options' => ['min' => 10, 'max' => 22, 'step' => 0.5, 'unit' => 'px'], 'rule' => [self::class, 'halfPx']],
        'radius' => ['type' => 'range', 'label' => 'Corner roundness', 'default' => 14, 'help' => '',
            'options' => ['min' => 0, 'max' => 30, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'above' => ['type' => 'range', 'label' => 'Space above', 'default' => 24,
            'help' => 'Phones and laptops. On a phone, a number typed in the strip’s own “Space above” on the Mobile sections tab wins over this one.',
            'options' => ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'below' => ['type' => 'range', 'label' => 'Space below', 'default' => 24, 'help' => 'Phones and laptops.',
            'options' => ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
    ];

    public const TABS = [
        'giftstrip' => ['Free gifts strip',
            'The “Free gifts with every order” strip on the product page: on or off, where it sits, its words in English and Arabic, its colours, effects and sizes.',
            ['on', 'desk', 'line1', 'line2', 'line1_ar', 'line2_ar', 'c1', 'c2', 'c3', 'ink', 'icon_bg', 'icon', 'confetti', 'shine', 'speed', 'height', 'fs1', 'fs2', 'radius', 'above', 'below']],
    ];

    public const POLICY = [
        'max' => 80,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    /** The English defaults' InterfaceStrings keys, so /ar reads Arabic while they are unchanged. */
    private const KEYED = [
        'line1' => 'store.product.gift_strip_1',
        'line2' => 'store.product.gift_strip_2',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $f) => $f['default'], self::fields());
    }

    /**
     * Every value, saved or shipped — and every one re-cast on the way OUT,
     * so a row written past save() (an import, a hand edit on the server)
     * still cannot put anything but its own type on the page.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $out = [];

        foreach (self::fields() as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $this->memo = $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        $fields = self::fields();

        foreach ($values as $key => $value) {
            if (isset($fields[$key])) {
                $this->settings->set(self::PREFIX.$key, ModuleSchema::cast($fields[$key], $value));
            }
        }

        $this->memo = null;
    }

    /** @return list<array<string, mixed>> the screen's ModuleSchema::tabs() payload */
    public function tabs(): array
    {
        return ModuleSchema::tabs(self::SCHEMA, self::TABS, $this->all(), self::POLICY);
    }

    /**
     * A font size: a number to the nearest half pixel, inside the field's own
     * bounds; anything else is the default. (A `range` would cast to an int and
     * turn the design's 15.5 into 15.)
     *
     * @param  array<string, mixed>  $field
     */
    public static function halfPx(mixed $raw, array $field): float
    {
        if (! is_numeric($raw) || is_bool($raw)) {
            return (float) $field['default'];
        }

        $v = (float) $raw;

        if (! is_finite($v)) {
            return (float) $field['default'];
        }

        $o = is_array($field['options'] ?? null) ? $field['options'] : [];

        return max((float) ($o['min'] ?? 10), min((float) ($o['max'] ?? 24), round($v * 2) / 2));
    }

    /**
     * A whole number of pixels inside the field's own bounds; anything that is
     * not a number is the default (ModuleSchema's clamp would read "lots" as 0).
     *
     * @param  array<string, mixed>  $field
     */
    public static function intPx(mixed $raw, array $field): int
    {
        if (! is_numeric($raw) || is_bool($raw) || ! is_finite((float) $raw)) {
            return (int) $field['default'];
        }

        $o = is_array($field['options'] ?? null) ? $field['options'] : [];

        return (int) max((int) ($o['min'] ?? 0), min((int) ($o['max'] ?? 0), (int) round((float) $raw)));
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    public function on(): bool
    {
        return (bool) ($this->all()['on'] ?? false);
    }

    /** One of a select's own keys, or its default. Rule 5's second lock. */
    public function choice(string $key): string
    {
        $f = self::fields()[$key];
        $v = (string) ($this->all()[$key] ?? '');

        return is_array($f['options']) && array_key_exists($v, $f['options']) ? $v : (string) $f['default'];
    }

    /** Where the laptop draws it: a DESK key. */
    public function desk(): string
    {
        return $this->choice('desk');
    }

    /** The DOM anchor the template draws the element at — 'off' sits in the default place, hidden on laptops. */
    public function anchor(): string
    {
        $d = $this->desk();

        return $d === 'off' ? 'paylater' : $d;
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    /**
     * A line of words in the page's language. English: his line. Arabic: his
     * Arabic line, or — while it is empty — the InterfaceStrings key, which
     * prints the published Arabic; never his English on the Arabic page unless
     * the key has no Arabic yet.
     */
    public function line(int $n): string
    {
        $key = 'line'.($n === 2 ? 2 : 1);
        $all = $this->all();

        if (! Locale::isDefault() && Locale::current() === 'ar') {
            $ar = trim((string) ($all[$key.'_ar'] ?? ''));

            if ($ar !== '') {
                return $ar;
            }

            // His English changed and no Arabic typed: the standard Arabic.
            return (string) __(self::KEYED[$key]);
        }

        $value = trim((string) ($all[$key] ?? ''));

        return $value;
    }

    /** Literal class names only. */
    public function classes(): string
    {
        $out = [];

        if (! $this->flag('shine')) {
            $out[] = 'gs-noshine';
        } elseif (($s = $this->choice('speed')) !== 'normal') {
            $out[] = 'gs-sp-'.$s;
        }

        if ($this->desk() !== 'paylater') {
            $out[] = 'gs-d-'.$this->desk();
        }

        return $out === [] ? '' : ' '.implode(' ', $out);
    }

    /**
     * The strip's own custom properties — only those that differ from the
     * design, so the shipped strip has no style attribute at all. Integers,
     * half-pixel numbers and `#RRGGBB` only.
     */
    public function style(): string
    {
        $all = $this->all();
        $def = self::defaults();
        $out = [];

        foreach (['c1', 'c2', 'c3', 'ink'] as $k) {
            $hex = self::hex((string) $all[$k], (string) $def[$k]);

            if ($hex !== strtoupper((string) $def[$k])) {
                $out[] = '--gs-'.$k.':'.$hex;
            }
        }

        $icon = self::hex((string) $all['icon_bg'], (string) $def['icon_bg']);

        if ($icon !== strtoupper((string) $def['icon_bg'])) {
            [$r, $g, $b] = sscanf(substr($icon, 1), '%2x%2x%2x');
            $out[] = '--gs-ib:rgba('.(int) $r.','.(int) $g.','.(int) $b.',.55)';
        }

        $px = ['height' => 'h', 'radius' => 'r', 'above' => 'mt', 'below' => 'mb'];

        foreach ($px as $k => $var) {
            $f = self::fields()[$k];
            $v = max((int) $f['options']['min'], min((int) $f['options']['max'], (int) $all[$k]));

            if ($v !== (int) $def[$k]) {
                $out[] = '--gs-'.$var.':'.$v.'px';
            }
        }

        foreach (['fs1', 'fs2'] as $k) {
            $v = self::halfPx($all[$k], self::fields()[$k]);

            if ($v !== (float) $def[$k]) {
                $out[] = '--gs-'.$k.':'.rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.').'px';
            }
        }

        return implode(';', $out);
    }

    /** `#RRGGBB`, upper case, or the default. Three digits are expanded. */
    public static function hex(string $raw, string $default): string
    {
        $v = strtoupper(ltrim(trim($raw), '#'));

        if (preg_match('/^[0-9A-F]{3}$/', $v) === 1) {
            $v = $v[0].$v[0].$v[1].$v[1].$v[2].$v[2];
        }

        return preg_match('/^[0-9A-F]{6}$/', $v) === 1 ? '#'.$v : strtoupper($default);
    }
}
