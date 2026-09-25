<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The header on phones — spacing, and the mark above the search field.
 *
 * Separate from HeaderSettings, which has fifty-eight settings covering both
 * widths. Nothing here touches the desktop header: every rule these values feed
 * lives inside the existing 900px media query.
 *
 * Emitted as custom properties on the <header> element, which already carries
 * HeaderSettings' own, so the stylesheet stays static and one shop's values
 * never leak into another's cache.
 */
class MobileHeader
{
    public const SCHEMA = [
        // ── Sides ──
        'match_page' => ['bool', 'Match the page', true, 'Uses the same inset as the homepage sections, so the logo lines up with the product grid. Off lets you set each side.'],
        'pad_left'   => ['range', 'Left', 12, '', ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'pad_right'  => ['range', 'Right', 12, '', ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],

        // ── Rows ──
        /*
         * THE DRIVER. The top row's height, and the scale everything standing
         * in that row is sized from — see --mh-fit at the foot of kbb.css.
         *
         * 44 is the number the row is built out of today: `.ib{width:44px;
         * height:44px}` and `.logo{min-height:44px}` from the mobile-polish
         * tap-target pass. The row MEASURES 46 because the burger is 46
         * (--mi-size), and that stays true here — the burger scales from the
         * same factor, so at 44 it is still exactly 46.
         */
        'row_h'      => ['range', 'Top row height', 44,
                         'The burger, the icons and their little counts are all sized from this, so a taller row makes them bigger instead of leaving them adrift in it. Below about 39px the three icons become small enough to fit beside the shop name instead of sitting on a line of their own, and the whole header shortens by about 50px at once — measured on a 360px screen; a wider phone crosses over lower.',
                         ['min' => 36, 'max' => 64, 'step' => 1, 'unit' => 'px']],
        'fit_text'   => ['bool', 'Scale the wordmark with it', false,
                         'Off, because the wordmark is the one thing on the row that cannot give way: it is a single unbroken line sharing the screen with a burger and three icons, and growing it is what makes the row wrap. Its own size is under Text size.'],
        'pad_top'    => ['range', 'Above the first row', 8, '', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'pad_bottom' => ['range', 'Below the first row', 0, 'The gap above the search field. This is now the only thing that sets it — the bar height no longer leaks space in here.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'row_gap'    => ['range', 'Between the rows', 6, 'Room for the divider to sit in.', ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'search_gap' => ['range', 'Below the search field', 10, '', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'item_gap'   => ['range', 'Gap between the menu, logo and icons', 10, 'Horizontal space between the items on the top row. Zero puts them hard against each other.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],

        // ── Search field ──
        // These target .sbox .search-in, the wrapper that draws the field. The
        // input inside it is deliberately borderless — a comment in kbb.css
        // explains that the two used to nest and drew a border inside a border.
        'search_full'   => ['bool', 'Full width', false, 'Runs edge to edge: no side rounding and no side borders, so the field meets the screen.'],
        'search_align'  => ['select', 'Icon and text sit at', 'page', 'Where the contents line up when Full width is on. The box always reaches both edges.', [
            'page' => 'The page edge — level with the logo',
            'field' => 'Where they are now — unmoved',
        ]],
        'search_pad'    => ['range', 'Space inside the field', 16, 'Between the field edge and the magnifier.', ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'search_radius' => ['range', 'Corner rounding', 12, 'Ignored while Full width is on.', ['min' => 0, 'max' => 26, 'step' => 1, 'unit' => 'px']],
        'search_border' => ['bool', 'Border', true, ''],
        'search_bg'     => ['colour', 'Field background', '#FFFFFF', ''],
        'search_icon'   => ['colour', 'Magnifier colour', '#E0567B', ''],
        'search_text'   => ['colour', 'Typed text colour', '#E0567B', ''],
        'search_ph'     => ['colour', 'Placeholder colour', '#8A7F86', ''],
        // 44 is where the field renders today, and no setting put it there:
        // `.sbox input{min-height:44px}` is a tap-target floor from the mobile
        // polish pass, and it outranks `header .sbox .search-in` carrying
        // Appearance → Header's own "Search field · phone". That setting tops
        // out at 44, so it could never move this field — see the rules this
        // variable feeds at the foot of kbb.css.
        'search_h'      => ['range', 'Field height', 44,
                            'The height of the box itself. 44px is what it measures today; a target much under that is hard to hit with a thumb.',
                            ['min' => 32, 'max' => 72, 'step' => 1, 'unit' => 'px']],

        // ── Text size ──
        // Zero means "leave it alone": cssVariables() emits nothing for a zero,
        // so the stylesheet keeps the fallback it already resolved to. That is
        // the only way a default can reproduce today's rendering for a shop
        // that has moved the wordmark size on the Header screen — a fixed
        // number here would drag it back to this file's idea of the default.
        'size_logo'     => ['range', 'Wordmark', 0,
                            'The shop name. Unchanged keeps the size set under Appearance → Header.',
                            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],
        'size_accent'   => ['range', 'Accent word', 0,
                            'The second half of the wordmark. Unchanged keeps it the same size as the first half.',
                            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],
        'size_search'   => ['range', 'Typed text', 0,
                            'What a shopper types into the search field. Unchanged is 16px — and 16px is a floor, not a preference: iOS Safari zooms the whole page in when a field under it takes focus, and does not zoom back out.',
                            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],
        'size_ph'       => ['range', 'Placeholder', 0,
                            'The prompt shown before anyone types. Unchanged keeps it the same size as the typed text.',
                            ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],
        'size_badge'    => ['range', 'Cart and wishlist count', 0,
                            'The small number on the two icons. Unchanged is 10px.',
                            ['min' => 0, 'max' => 18, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],
        'size_trend'    => ['range', 'Trending words', 0,
                            'The chips under the search field, when Appearance → Header switches them on. Unchanged is 12px.',
                            ['min' => 0, 'max' => 20, 'step' => 1, 'unit' => 'px', 'zero' => 'Unchanged']],

        // ── Icons ──
        // #2A2228 is --ink, which is what the marks inherit today through
        // body → a{color:inherit}; the signed-in green is the colour the
        // signed-in dot beside them has always used (HeaderSettings'
        // account_dot_col / --hd-dot). Neither is a new colour for this shop.
        'acct_out'      => ['colour', 'Account icon · signed out', '#2A2228', 'What the mark renders as today.'],
        'acct_in'       => ['colour', 'Account icon · signed in', '#1F9D55', 'The green the signed-in dot already uses.'],

        // ── Divider ──
        'divider'    => ['select', 'Divider above the search field', 'full', '', [
            'off'   => 'None',
            'hair'  => 'Inset hairline',
            'full'  => 'Full-width rule',
            'ticks' => 'Corner ticks',
            'soft'  => 'Soft shadow, no line',
        ]],
        'dv_colour'  => ['colour', 'Colour', '#2A2228', ''],
        'dv_alpha'   => ['range', 'Opacity', 16, '', ['min' => 4, 'max' => 100, 'step' => 2, 'unit' => '%']],
        'dv_width'   => ['range', 'Thickness', 1, '', ['min' => 1, 'max' => 4, 'step' => 1, 'unit' => 'px']],
        'dv_inset'   => ['range', 'Distance from the sides', 0, 'Ignored by the full-width rule, which always runs edge to edge.', ['min' => 0, 'max' => 40, 'step' => 2, 'unit' => 'px']],
        'dv_length'  => ['range', 'Tick length', 26, 'Corner ticks only.', ['min' => 12, 'max' => 70, 'step' => 2, 'unit' => 'px']],
    ];

    public const TABS = [
        'spacing' => ['Spacing', 'How far in the header sits, and how tall its rows are. Phones only.',
                      ['row_h', 'fit_text', 'match_page', 'pad_left', 'pad_right', 'pad_top', 'item_gap', 'pad_bottom', 'row_gap', 'search_gap']],
        'type'    => ['Text size', 'Every piece of text the phone header draws. Each one starts at Unchanged, which is exactly what it renders now.',
                      ['size_logo', 'size_accent', 'size_search', 'size_ph', 'size_badge', 'size_trend']],
        'search'  => ['Search field', 'The shape, height and colours of the field itself.',
                      ['search_full', 'search_align', 'search_h', 'search_pad', 'search_radius', 'search_border', 'search_bg',
                       'search_icon', 'search_text', 'search_ph']],
        'icons'   => ['Icons', 'The account, wishlist and cart marks on the right of the top row.',
                      ['acct_out', 'acct_in']],
        'divider' => ['Divider', 'The mark between the logo row and the search field.',
                      ['divider', 'dv_colour', 'dv_alpha', 'dv_width', 'dv_inset', 'dv_length']],
    ];

    /** What the homepage sections use, so "match the page" has something to match. */
    private const PAGE_INSET = 12;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get('mhd_' . $key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        /*
         * THE SIDES REPORT WHAT IS IN FORCE, not what is stored under them.
         *
         * "Match the page" used to be applied in cssVariables() and nowhere
         * else, so `all()` — which is what the admin screen is drawn from —
         * handed back a Left and a Right that the header then threw away. The
         * screen dims those two rows while the toggle is on (opacity .45) but
         * leaves the sliders live, so they moved, they saved, and the phone
         * did not budge. That is the "left right spacing don't work" report.
         *
         * Reconciling here rather than at render time means there is one
         * answer to "how far in is the header", and the screen, the preview
         * and the storefront all read it. The values stored underneath are
         * left untouched; save() is what brings them back into line.
         */
        if ($out['match_page']) {
            $out['pad_left'] = self::PAGE_INSET;
            $out['pad_right'] = self::PAGE_INSET;
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        /*
         * The toggle and the two numbers are one decision, so they are written
         * together. Without this a shop can sit on match_page = true with a
         * Left of 28 underneath it — which is exactly the state the screen
         * could put it in before, and exactly the state that looks like a bug
         * the next time somebody turns the toggle off.
         */
        if (array_key_exists('match_page', $values) && (bool) $values['match_page']) {
            $values['pad_left'] = self::PAGE_INSET;
            $values['pad_right'] = self::PAGE_INSET;
        }

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set('mhd_' . $key, $this->cast($key, $value));
            }
        }
    }

    /** This screen's point on ModuleSchema's four policy axes. Seven colour fields here stored a hex with no `#` until this moved to the shared cast; see ModuleSchema::cast(). */
    public const POLICY = [
        'max' => 120,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
    ];

    private function cast(string $key, mixed $value): mixed
    {
        return ModuleSchema::cast(
            ModuleSchema::field($key, self::SCHEMA[$key], self::POLICY),
            $value,
        );
    }

    /**
     * Which size control feeds which custom property.
     *
     * Kept as a map rather than written out below because every one of them is
     * emitted on the same condition — see the loop at the foot of this method.
     */
    private const SIZE_VARS = [
        'size_logo' => '--mh-logo',
        'size_accent' => '--mh-logoacc',
        'size_search' => '--mh-stsize',
        'size_ph' => '--mh-phsize',
        'size_badge' => '--mh-badge',
        'size_trend' => '--mh-trendsize',
    ];

    public function cssVariables(): string
    {
        $c = $this->all();

        /*
         * Straight out of all(), which has already applied "Match the page".
         * Deciding it here as well was the whole of the bug: two places
         * answered "how far in", the screen read one and the header read the
         * other.
         */
        $left = (int) $c['pad_left'];
        $right = (int) $c['pad_right'];

        $out = [
            '--mh-l:' . $left . 'px',
            '--mh-r:' . $right . 'px',
            '--mh-t:' . $c['pad_top'] . 'px',
            '--mh-b:' . $c['pad_bottom'] . 'px',
            '--mh-gap:' . $c['row_gap'] . 'px',
            '--mh-sgap:' . $c['search_gap'] . 'px',
            '--mh-igap:' . $c['item_gap'] . 'px',
            '--mh-dv:' . $this->rgba((string) $c['dv_colour'], (int) $c['dv_alpha']),
            '--mh-dvw:' . $c['dv_width'] . 'px',
            '--mh-dvin:' . $c['dv_inset'] . 'px',
            '--mh-dvlen:' . $c['dv_length'] . 'px',
            '--mh-srad:' . ($c['search_full'] ? '0' : $c['search_radius'] . 'px'),
            '--mh-spad:' . $c['search_pad'] . 'px',
            '--mh-sbg:' . $c['search_bg'],
            '--mh-sicon:' . $c['search_icon'],
            '--mh-stext:' . $c['search_text'],
            '--mh-sph:' . $c['search_ph'],
            '--mh-sh:' . (int) $c['search_h'] . 'px',
            /*
             * The same two heights again, as BARE NUMBERS.
             *
             * They are what the two scale factors are built from, and CSS
             * cannot divide by a length: `calc(var(--mh-rowh) / 44px)` is
             * invalid and the whole declaration is dropped. `calc(var(
             * --mh-rown) / 44)` is a number over a number, which is legal and
             * is 1 at the default — so every rule multiplied by it renders
             * exactly what it renders today.
             */
            '--mh-rowh:' . (int) $c['row_h'] . 'px',
            '--mh-rown:' . (int) $c['row_h'],
            '--mh-shn:' . (int) $c['search_h'],
            '--mh-acct:' . $c['acct_out'],
            '--mh-accti:' . $c['acct_in'],
        ];

        /*
         * A size left at Unchanged emits NOTHING.
         *
         * That is the whole reason these defaults can be honest. The rules in
         * kbb.css are written `font-size:var(--mh-logo,var(--hd-logo))` and
         * `font-size:var(--mh-logoacc,inherit)`, so an absent property leaves
         * the declaration resolving to exactly what it resolved to before this
         * control existed — including on a shop that has already moved the
         * wordmark size on the Header screen. Emitting a number here instead
         * would drag that shop's phone header back to this file's default the
         * moment the package landed.
         */
        foreach (self::SIZE_VARS as $key => $var) {
            $px = (int) $c[$key];

            if ($px > 0) {
                $out[] = $var . ':' . $px . 'px';
            }
        }

        return implode(';', $out);
    }

    /** The divider style, as a class on the header. */
    public function bodyClass(): string
    {
        $style = (string) $this->all()['divider'];

        $c = $this->all();

        return trim(implode(' ', array_filter([
            $style === 'off' ? '' : 'mhd-' . $style,
            $c['search_full'] ? 'mhs-full' : '',
            $c['search_full'] && $c['search_align'] === 'field' ? 'mhs-keep' : '',
            $c['search_border'] ? '' : 'mhs-noborder',
            $c['fit_text'] ? 'mhs-fittext' : '',
        ])));
    }

    /**
     * A hex colour at a percentage, as rgba.
     *
     * The divider needs to be faint, and a shopkeeper picking #2A2228 expects it
     * to look like a hairline rather than a black bar — so opacity is its own
     * control rather than something to encode into the colour.
     */
    private function rgba(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, max(0, min(100, $percent)) / 100);
    }
}
