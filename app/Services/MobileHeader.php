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
                      ['match_page', 'pad_left', 'pad_right', 'pad_top', 'item_gap', 'pad_bottom', 'row_gap', 'search_gap']],
        'search'  => ['Search field', 'The shape and colours of the field itself.',
                      ['search_full', 'search_align', 'search_pad', 'search_radius', 'search_border', 'search_bg',
                       'search_icon', 'search_text', 'search_ph']],
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

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set('mhd_' . $key, $this->cast($key, $value));
            }
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            'select' => isset($def[4][$value]) ? (string) $value : $def[2],
            'colour' => \App\Support\Color::isValidHex((string) $value) ? strtoupper((string) $value) : $def[2],
            default => mb_substr(trim((string) $value), 0, 120),
        };
    }

    public function cssVariables(): string
    {
        $c = $this->all();

        $left = $c['match_page'] ? self::PAGE_INSET : (int) $c['pad_left'];
        $right = $c['match_page'] ? self::PAGE_INSET : (int) $c['pad_right'];

        return implode(';', [
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
        ]);
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
