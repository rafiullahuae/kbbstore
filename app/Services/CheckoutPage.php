<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The checkout PAGE — its spacing, and nothing else.
 *
 * Appearance → Checkout page, two screens: Desktop and Mobile. The brief was
 * "the same options as we built for cart page, like squeezing, spacings,
 * paddings, control for every section of the checkout page ... please don't
 * disturb any number of sections on desktop checkout and mobile checkout
 * page." So this service moves numbers and moves nothing else: no section is
 * added, removed, reordered or renamed by anything in here, and there is no
 * layout switch. The four numbered sections are the four numbered sections at
 * every value of every control below.
 *
 * ── WHY THE DEFAULTS CHANGE ZERO BYTES ──────────────────────────────────────
 *
 * cssVariables() emits a property ONLY where the saved value differs from the
 * schema default, and styleAttr() emits no attribute at all when that list is
 * empty. A shop that has never opened this screen renders the checkout page
 * byte for byte as before — which is what tests/Feature/StorefrontEnglish-
 * UnchangedTest compares, and what a live shop mid-order requires. The
 * stylesheet carries every default a second time as the var() fallback, so the
 * page is correct with no attribute on it at all.
 *
 * ── DESKTOP AND MOBILE ARE SEPARATE VALUES, NOT ONE VALUE AND A BREAKPOINT ──
 *
 * The owner asked for two screens, and the two are genuinely independent: a
 * 16px section on a 594px column and a 16px section on a 350px one are not the
 * same design decision. So every spacing that exists on both surfaces is
 * stored twice, `d_*` and `m_*`.
 *
 * They reach the page as two SETS of custom properties, and the stylesheet —
 * not this class — picks between them:
 *
 *     .kbb-checkout        { --cop-secpad: var(--cop-d-secpad, 16px) }
 *     @media(max-width:900px){
 *       .kbb-checkout      { --cop-secpad: var(--cop-m-secpad, 16px) }
 *     }
 *
 * and every rule reads `--cop-secpad`. It has to be that way round. An inline
 * `style` attribute beats every stylesheet rule including one inside a media
 * query, so if this class emitted `--cop-secpad` directly, the mobile
 * reassignment could never win and the Mobile screen would save, report
 * success and move nothing. The inline attribute carries only the `d-` and
 * `m-` sources; the stylesheet does the choosing.
 *
 * ── THE ONE RULE THAT IS NOT A NUMBER ───────────────────────────────────────
 *
 * `d_sticky` switches `position:sticky` on the summary column off. A custom
 * property cannot do that — it can change a number inside a declaration, never
 * whether the declaration applies — so it ships as a class, `cop-nostick`, the
 * same way CartPage handles its structural switches.
 */
class CheckoutPage
{
    /**
     * key => [type, label, default, help, options]
     *
     * Types: range, bool. Nothing here is text: this screen sets spacing, and
     * the checkout's wording is translated copy that belongs in the language
     * files, not in a settings row.
     */
    public const SCHEMA = [
        // ── Desktop ──
        'd_max'       => ['range', 'Page width', 1040,
                          'How wide the whole checkout may grow on a large screen. Both columns and the gap between them live inside this. 1040 is the width the page has today.',
                          ['min' => 880, 'max' => 1440, 'step' => 20, 'unit' => 'px']],
        'd_aside'     => ['range', 'Summary column width', 380,
                          'The right-hand order summary. The form column takes whatever is left, so widening this narrows the form rather than widening the page.',
                          ['min' => 280, 'max' => 520, 'step' => 10, 'unit' => 'px']],
        'd_gap'       => ['range', 'Space between the two columns', 26,
                          'The gutter between the form and the summary.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_pad_x'     => ['range', 'Page padding — sides', 20,
                          'Breathing room between the page edge and the columns. On a screen wider than the page width above, this sits inside the centred block and does not move it.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_pad_y'     => ['range', 'Page padding — top', 22,
                          'The gap under the secure-checkout header.',
                          ['min' => 0, 'max' => 64, 'step' => 2, 'unit' => 'px']],
        'd_block_gap' => ['range', 'Space between blocks', 16,
                          'Between the heading and the coupon box, and between the coupon box and the card that holds the four numbered sections.',
                          ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'd_sec_pad'   => ['range', 'Padding inside each section', 16,
                          'Applies to all four numbered sections at once — Contact, Shipping address, Delivery and Payment. Their heading bars are worked out from this number, so the bars keep meeting the card edge at every value instead of drifting away from it.',
                          ['min' => 6, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_aside_pad' => ['range', 'Padding inside the summary', 17,
                          'The right-hand column only.',
                          ['min' => 6, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'd_sticky'    => ['bool', 'Summary follows the scroll', true,
                          'On is what the page does today: the summary stays in view while the form scrolls past it. Off leaves it at the top of the column.'],
        'd_sticky_top' => ['range', 'Summary stops at', 18,
                           'How far below the top of the window the summary parks itself once it has caught up. Only read while the switch above is on.',
                           ['min' => 0, 'max' => 96, 'step' => 2, 'unit' => 'px']],

        // ── Mobile ──
        'm_pad_x'     => ['range', 'Page padding — sides', 20,
                          'The gap between the screen edge and every block on the page. The checkout is one column on a phone, so this is the page margin.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_pad_y'     => ['range', 'Page padding — top', 22,
                          'The gap under the secure-checkout header.',
                          ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'm_gap'       => ['range', 'Space between stacked blocks', 14,
                          'The one column is a grid, and this is the gap between the summary at the top and the form below it.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_block_gap' => ['range', 'Space between blocks', 16,
                          'Between the heading and the coupon box, between the coupon box and the card of sections, and above the Place order box at the foot.',
                          ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'm_sec_pad'   => ['range', 'Padding inside each section', 16,
                          'All four numbered sections, and the Place order box below them. Their heading bars follow it.',
                          ['min' => 6, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'm_aside_pad' => ['range', 'Padding inside the summary', 14,
                          'The order-summary card at the top of the phone page.',
                          ['min' => 6, 'max' => 32, 'step' => 1, 'unit' => 'px']],
    ];

    public const TABS = [
        'desktop' => ['Desktop', 'The two-column checkout, from 901px up. Nothing on this tab can reach a phone.',
                      ['d_max', 'd_aside', 'd_gap', 'd_pad_x', 'd_pad_y',
                       'd_block_gap', 'd_sec_pad', 'd_aside_pad',
                       'd_sticky', 'd_sticky_top']],
        'mobile'  => ['Mobile', 'The single-column checkout, at 900px and below. Nothing on this tab can reach a desktop.',
                      ['m_pad_x', 'm_pad_y', 'm_gap', 'm_block_gap', 'm_sec_pad', 'm_aside_pad']],
    ];

    /**
     * Where the stylesheet switches from the desktop set to the mobile one.
     *
     * Stated here so a test can assert the two agree. It is NOT a setting: the
     * query lives in a built stylesheet and a custom property cannot be read
     * by a media query — the query is resolved before custom properties
     * exist — so a control for it would have to re-emit the whole mobile block
     * from Blade. Not worth it for a number nobody has asked to move.
     */
    public const MOBILE_MAX = 900;

    private const PREFIX = 'checkoutpage_';

    /**
     * key => the custom property it is emitted as.
     *
     * The `d-`/`m-` halves of the name are what the stylesheet's media query
     * chooses between; see the class note. `d_sticky` is absent because it is
     * a class, not a property.
     */
    private const VARS = [
        'd_max'        => '--cop-d-max',
        'd_aside'      => '--cop-d-aside',
        'd_gap'        => '--cop-d-gap',
        'd_pad_x'      => '--cop-d-padx',
        'd_pad_y'      => '--cop-d-pady',
        'd_block_gap'  => '--cop-d-block',
        'd_sec_pad'    => '--cop-d-secpad',
        'd_aside_pad'  => '--cop-d-asidepad',
        'd_sticky_top' => '--cop-d-sticktop',
        'm_pad_x'      => '--cop-m-padx',
        'm_pad_y'      => '--cop-m-pady',
        'm_gap'        => '--cop-m-gap',
        'm_block_gap'  => '--cop-m-block',
        'm_sec_pad'    => '--cop-m-secpad',
        'm_aside_pad'  => '--cop-m-asidepad',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
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
     * A stored value, forced back inside its schema.
     *
     * The clamp is here and not only in the browser, because the browser is
     * not the only thing that can POST to the endpoint and a slider that
     * accepts 9999 is a page with no layout left.
     */
    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return $value;
        }

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max(
                (int) $def[4]['min'],
                min((int) $def[4]['max'], (int) $value),
            ),
            default => $value,
        };
    }

    /**
     * Only what the owner has actually moved.
     *
     * A value equal to its default is left out entirely, so the attribute is
     * absent on a shop that has never opened the screen and carries exactly
     * the properties that differ on one that has. That is what keeps the
     * default render byte-identical, and it also keeps the attribute short
     * enough to read in a page source when something looks wrong.
     */
    public function cssVariables(): string
    {
        $c = $this->all();
        $out = [];

        foreach (self::VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$c[$key].'px';
            }
        }

        return implode(';', $out);
    }

    /** `style="..."`, or nothing at all. */
    public function styleAttr(): string
    {
        $vars = $this->cssVariables();

        return $vars === '' ? '' : ' style="'.e($vars).'"';
    }

    /**
     * Structural switches as classes. Leading space included, or an empty
     * string — the view interpolates it straight after `kbb-checkout`.
     */
    public function bodyClass(): string
    {
        return $this->get('d_sticky') ? '' : ' cop-nostick';
    }
}
