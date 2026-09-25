<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Everything in the site header, in one place.
 *
 * Grouped into tabs so the screen stays readable rather than becoming one long
 * column. Nothing here touches the mobile menu sheet — that keeps its own
 * screen, since it is a different surface with different concerns.
 */
class HeaderSettings
{
    /** key => [type, label, default, help, options] */
    public const SCHEMA = [
        // ── Bar ──
        'sticky'          => ['bool',   'Stick to the top', true, 'The bar stays put as the page scrolls.'],
        'bar_height'      => ['range',  'Bar height · desktop', 40, '', ['min' => 36, 'max' => 96, 'step' => 2, 'unit' => 'px']],
        'bar_height_mobile' => ['range', 'Bar height · phone', 40, '', ['min' => 36, 'max' => 72, 'step' => 2, 'unit' => 'px']],
        'nav_height'      => ['range',  'Category bar height', 30, '', ['min' => 24, 'max' => 52, 'step' => 2, 'unit' => 'px']],
        'bar_pad_y'       => ['range',  'Bar padding · desktop', 0, 'Space above and below the bar row.', ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'bar_pad_y_mobile'=> ['range',  'Bar padding · phone', 0, '', ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'nav_pad_y'       => ['range',  'Category bar padding', 0, 'Space above and below the category row.', ['min' => 0, 'max' => 16, 'step' => 1, 'unit' => 'px']],
        'field_height'    => ['range',  'Search field height', 30, '', ['min' => 26, 'max' => 44, 'step' => 2, 'unit' => 'px']],
        'field_height_mobile' => ['range', 'Search field · phone', 30, '', ['min' => 26, 'max' => 44, 'step' => 2, 'unit' => 'px']],
        'bar_bg'          => ['colour', 'Background', '#FFFFFF', ''],
        'bar_border'      => ['bool',   'Bottom border', true, ''],
        'shadow_on_scroll'=> ['bool',   'Shadow once scrolled', true, 'A soft shadow appears after the page moves.'],
        'max_width'       => ['range',  'Content width', 1280, '', ['min' => 1040, 'max' => 1600, 'step' => 20, 'unit' => 'px']],

        // ── Logo ──
        'logo_text'       => ['text',   'Wordmark', 'K-Beauty', 'The first half, in ink.'],
        'logo_accent'     => ['text',   'Accent word', 'Bliss', 'The second half, in the accent colour.'],
        'logo_size'       => ['range',  'Wordmark size', 22, '', ['min' => 16, 'max' => 34, 'step' => 1, 'unit' => 'px']],
        'logo_colour'     => ['colour', 'Wordmark colour', '#2A2228', ''],
        'logo_accent_col' => ['colour', 'Accent colour', '#E0567B', ''],

        // ── Search ──
        'search_show'     => ['bool',   'Search box', true, ''],
        'search_text'     => ['text',   'Placeholder', 'Search skincare, brands…', 'Use {n} for the product count.'],
        'search_radius'   => ['range',  'Field roundness', 99, '', ['min' => 6, 'max' => 99, 'step' => 3, 'unit' => 'px']],
        'trending_show'   => ['bool',   'Trending row', false, 'The chips under the search box. Off by default — they appear in the search panel instead.'],
        'trending_words'  => ['tags',   'The words', 'Madeca, PDRN, Retinol, Dark spots, Age-R Booster Pro, Capsule Cream, Medicube, Anua, Beauty of Joseon, COSRX, SKIN 1004, Acne',
                              'Shown in this order. Add your own, or pick from brands and categories.'],
        'trending_limit'  => ['range',  'Trending words · desktop', 16, '', ['min' => 3, 'max' => 20, 'step' => 1, 'unit' => '']],
        'trending_limit_mobile' => ['range', 'Trending words · phone', 8, '', ['min' => 3, 'max' => 12, 'step' => 1, 'unit' => '']],
        'search_panel'    => ['select', 'Panel before typing', 'recent-left',
                              'What the desktop panel shows while the field is empty.',
                              ['wide-then-two' => 'One wide panel, then two columns',
                               'two-columns' => 'Two columns from the start',
                               'recent-left' => 'Most-searched on the left']],
        'search_recent_count' => ['range', 'Most-searched terms', 5,
                                  'Taken from everyone’s searches over the last seven days.',
                                  ['min' => 3, 'max' => 10, 'step' => 1, 'unit' => '']],
        'search_results_max'  => ['range', 'Results in the panel', 5, 'How many products the panel lists before the view-all link.', ['min' => 3, 'max' => 8, 'step' => 1, 'unit' => '']],
        'search_row_size'     => ['select', 'Result row size', 'compact', 'Compact fits five results without scrolling.',
                                  ['compact' => 'Compact', 'regular' => 'Regular']],
        'search_group_rule'   => ['bool',   'Line between groups', true, 'A divider above Brands and Categories.'],
        'search_row_rule'     => ['bool',   'Line between results', true, 'A hairline between one product and the next.'],
        'search_native_clear' => ['bool',   'Browser clear button', false, 'The grey cross the browser draws inside the field. Off, since the panel has its own.'],
        'search_brands_phone' => ['bool',   'Brand matches on phone', false, 'Brands appear in results on desktop only by default.'],
        'trending_hide'   => ['bool',   'Hide it on scroll', true, 'Gives the row back once reading starts.'],

        // ── Search: behaviour (moved to Store → Site Search; kept here so
        //    existing values are never lost — see the note above TABS) ──
        'search_min_chars'      => ['range', 'Minimum characters', 2, 'How many letters before suggestions appear.', ['min' => 1, 'max' => 4, 'step' => 1, 'unit' => '']],
        'search_limit_categories' => ['range', 'Category matches', 3, 'How many categories the panel can show.', ['min' => 0, 'max' => 6, 'step' => 1, 'unit' => '']],
        'search_limit_brands'   => ['range', 'Brand matches', 3, 'How many brands the panel can show.', ['min' => 0, 'max' => 6, 'step' => 1, 'unit' => '']],

        // ── Search: extended results (brand-aware matching) ──
        'search_extended_enabled' => ['bool', 'Extended search results', false,
                                      'Recognise a brand name in the query (e.g. "Medicube Serum") and match accordingly. Off by default.'],
        'search_extended_strict_brand' => ['bool', 'Brand-only queries stay strict', true,
                                      'Searching just a brand name (e.g. "Medicube") never pads the list with other brands\' products, even if that means fewer results.'],
        'search_extended_partial_brand_match' => ['bool', 'Match multi-word brands while typing', true,
                                      'A brand like "Beauty of Joseon" is recognised from "beauty of" onward, not only once fully typed.'],
        'search_extended_broaden_others' => ['bool', 'Widen brand + word searches to other brands', true,
                                      'For "Medicube Serum": the brand\'s own serums come first, then serums from other brands too. Off shows only that brand\'s.'],

        // ── Search: styles & colours ──
        'search_style_accent'      => ['colour', 'Accent colour', '#E0567B', 'Prices, the view-all button, active states.'],
        'search_style_accent_deep' => ['colour', 'Accent colour · hover', '#C13E63', 'Used on hover and for emphasis.'],
        'search_style_chip_bg'     => ['colour', 'Chip background', '#F3EEEF', 'Trending words and brand pills, at rest.'],
        'search_style_chip_text'   => ['colour', 'Chip text', '#5E545A', ''],
        'search_style_radius'      => ['range', 'Corner roundness', 14, 'The panel and its cards.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],

        // ── Icons ──
        'icon_account'    => ['bool',   'Account', true, ''],
        'account_menu'    => ['bool',   'Account menu', true, 'A panel for signed-in customers, on hover or on tap.'],
        'account_dot'     => ['bool',   'Signed-in dot', true, 'A small mark on the icon once someone is signed in.'],
        'account_dot_col' => ['colour', 'Dot colour', '#1F9D55', ''],
        'account_check'   => ['bool',   'Sum before registering', true, 'A small question that keeps automated sign-ups out.'],
        'icon_wishlist'   => ['bool',   'Wishlist', true, ''],
        'icon_cart'       => ['bool',   'Cart', true, ''],
        'icon_size'       => ['range',  'Icon size', 21, '', ['min' => 16, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'badge_bg'        => ['colour', 'Count badge', '#E0567B', ''],

        // ── Menu icon ──
        'menu_icon'       => ['select', 'Icon', 'tiles', 'The control that opens the mobile menu.',
                              ['tiles' => 'Colour tiles', 'bars' => 'Three bars', 'bars-cycle' => 'Bars · brand cycle',
                               'bars-tri' => 'Bars · three colours', 'bars-gradient' => 'Bars · gradient',
                               'bars-glow' => 'Bars · soft glow', 'chip' => 'Bars in a chip', 'ring' => 'Bars in a ring',
                               'dots9' => 'Nine dots', 'dots3' => 'Three dots']],
        'menu_icon_speed' => ['range',  'Effect speed', 5, 'Seconds for one full cycle.', ['min' => 2, 'max' => 12, 'step' => 1, 'unit' => 's']],
        'menu_icon_size'  => ['range',  'Icon size', 46, '', ['min' => 32, 'max' => 52, 'step' => 2, 'unit' => 'px']],
        'menu_icon_c1'    => ['colour', 'Colour one', '#E0567B', ''],
        'menu_icon_c2'    => ['colour', 'Colour two', '#E8A33D', ''],
        'menu_icon_c3'    => ['colour', 'Colour three', '#1F9D55', ''],

        // ── Navigation ──
        'nav_show'        => ['bool',   'Category bar', true, 'The row of categories under the search.'],
        'nav_uppercase'   => ['bool',   'Uppercase', false, ''],
        'nav_size'        => ['range',  'Text size', 14, '', ['min' => 11, 'max' => 17, 'step' => 1, 'unit' => 'px']],
        'nav_gap'         => ['range',  'Spacing', 26, '', ['min' => 12, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'nav_hot_colour'  => ['colour', 'Sale item colour', '#E23B57', 'Applied to items marked as a sale.'],

        // ── Support ──
        'support_show'    => ['bool',   'Support block', true, 'The 24/7 WhatsApp block on the right.'],
        'support_label'   => ['text',   'Wording', '24/7 support', ''],
        'support_icon_bg' => ['colour', 'Icon background', '#E8F7EE', ''],
        'support_icon_fg' => ['colour', 'Icon colour', '#1F9D55', ''],
    ];

    /** tab key => [label, description, field keys] */
    public const TABS = [
        'bar'     => ['Bar', 'Size, colour and how it behaves on scroll.',
                      ['sticky', 'bar_height', 'bar_height_mobile', 'bar_pad_y', 'bar_pad_y_mobile', 'nav_height', 'nav_pad_y', 'field_height',
                       'field_height_mobile', 'bar_bg', 'bar_border', 'shadow_on_scroll', 'max_width']],
        'logo'    => ['Logo', 'The wordmark and its colours.',
                      ['logo_text', 'logo_accent', 'logo_size', 'logo_colour', 'logo_accent_col']],
        // 'search' tab moved to Store → Site Search. The fields themselves
        // stay in SCHEMA above — nothing here reads or writes them anymore,
        // but the merge in save() means any value already saved for them
        // stays exactly as it was.
        'icons'   => ['Icons', 'Account, wishlist and cart.',
                      ['icon_account', 'account_menu', 'account_dot', 'account_dot_col', 'account_check', 'icon_wishlist', 'icon_cart', 'icon_size', 'badge_bg']],
        'menu'    => ['Menu icon', 'The control that opens the mobile menu.',
                      ['menu_icon', 'menu_icon_speed', 'menu_icon_size', 'menu_icon_c1', 'menu_icon_c2', 'menu_icon_c3']],
        'nav'     => ['Navigation', 'The category bar.',
                      ['nav_show', 'nav_uppercase', 'nav_size', 'nav_gap', 'nav_hot_colour']],
        'support' => ['Support', 'The WhatsApp block.',
                      ['support_show', 'support_label', 'support_icon_bg', 'support_icon_fg']],
    ];

    public function __construct(private SettingsService $settings) {}

    public function all(): array
    {
        $saved = $this->settings->get('header_settings');
        $saved = is_array($saved) ? $saved : [];

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = array_key_exists($key, $saved) ? $this->cast($key, $saved[$key]) : $def[2];
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** This screen's point on ModuleSchema's four policy axes. */
    public const POLICY = [
        'max' => 120,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
    ];

    public function cast(string $key, mixed $value): mixed
    {
        if (! isset(self::SCHEMA[$key])) {
            return null;
        }

        return ModuleSchema::cast(
            ModuleSchema::field($key, self::SCHEMA[$key], self::POLICY),
            $value,
        );
    }

    /**
     * Trending words as a tidy comma list.
     *
     * Accepts either an array from the picker or a typed string, so pasting a
     * list works as well as clicking suggestions.
     */
    private function tags(mixed $value, string $default): string
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        $clean = [];

        foreach ($items as $item) {
            $item = trim(preg_replace('/\s+/', ' ', (string) $item));

            if ($item === '' || mb_strlen($item) > 40) {
                continue;
            }

            // Case-insensitive de-duplication, keeping the first spelling.
            if (! in_array(mb_strtolower($item), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $item;
            }
        }

        return $clean === [] ? $default : implode(', ', array_slice($clean, 0, 20));
    }

    /** @return string[] */
    public function trendingWords(): array
    {
        $words = array_filter(array_map('trim', explode(',', (string) $this->get('trending_words'))));

        return array_slice(array_values($words), 0, (int) $this->get('trending_limit'));
    }

    public function save(array $values): void
    {
        // Merged into whatever is already saved, not replacing it outright.
        // The admin screens that call this each submit only the fields their
        // own tabs know about — Header submits its tabs, Site Search submits
        // its own — so a straight replace here would silently reset every
        // field the caller didn't happen to include back to its default the
        // next time a different screen saved anything at all.
        $saved = $this->settings->get('header_settings');
        $clean = is_array($saved) ? $saved : [];

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $clean[$key] = $this->cast($key, $value);
            }
        }

        $this->settings->set('header_settings', $clean);
    }

    /** Appearance as custom properties, so the stylesheet stays cacheable. */
    public function cssVariables(): string
    {
        $c = $this->all();

        return implode(';', [
            '--hd-h:' . $c['bar_height'] . 'px',
            '--hd-h-m:' . $c['bar_height_mobile'] . 'px',
            '--hd-nav-h:' . $c['nav_height'] . 'px',
            '--hd-pad:' . $c['bar_pad_y'] . 'px',
            '--hd-pad-m:' . $c['bar_pad_y_mobile'] . 'px',
            '--hd-nav-pad:' . $c['nav_pad_y'] . 'px',
            '--hd-dot:' . $c['account_dot_col'],
            '--hd-field:' . $c['field_height'] . 'px',
            '--hd-field-m:' . $c['field_height_mobile'] . 'px',
            '--hd-bg:' . $c['bar_bg'],
            '--hd-max:' . $c['max_width'] . 'px',
            '--hd-logo:' . $c['logo_size'] . 'px',
            '--hd-logo-c:' . $c['logo_colour'],
            '--hd-logo-a:' . $c['logo_accent_col'],
            '--hd-radius:' . $c['search_radius'] . 'px',
            '--hd-icon:' . $c['icon_size'] . 'px',
            '--hd-badge:' . $c['badge_bg'],
            '--hd-nav:' . $c['nav_size'] . 'px',
            '--hd-gap:' . $c['nav_gap'] . 'px',
            '--hd-hot:' . $c['nav_hot_colour'],
            '--hd-sup-bg:' . $c['support_icon_bg'],
            '--hd-sup-fg:' . $c['support_icon_fg'],
            '--mi-size:' . $c['menu_icon_size'] . 'px',
            '--mi-speed:' . $c['menu_icon_speed'] . 's',
            '--mi-c1:' . $c['menu_icon_c1'],
            '--mi-c2:' . $c['menu_icon_c2'],
            '--mi-c3:' . $c['menu_icon_c3'],
            '--sg-accent:' . $c['search_style_accent'],
            '--sg-accent-deep:' . $c['search_style_accent_deep'],
            '--sg-chip-bg:' . $c['search_style_chip_bg'],
            '--sg-chip-fg:' . $c['search_style_chip_text'],
            '--sg-radius:' . $c['search_style_radius'] . 'px',
        ]);
    }

    /** Structural switches that CSS alone cannot express. */
    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['sticky'] ? 'hd-sticky' : '',
            $c['bar_border'] ? 'hd-border' : '',
            $c['shadow_on_scroll'] ? 'hd-shadow' : '',
            $c['nav_uppercase'] ? 'hd-upper' : '',
            $c['trending_hide'] ? 'hd-trendhide' : '',
        ])));
    }

    /**
     * Which markup the chosen icon needs.
     *
     * Tiles need four squares, the dot icons need dots, everything else three
     * bars — so the template has to know the family, not just the class.
     */
    public function menuIconFamily(): string
    {
        return match ($this->get('menu_icon')) {
            'tiles' => 'tiles',
            'dots9' => 'dots9',
            'dots3' => 'dots3',
            default => 'bars',
        };
    }
}
