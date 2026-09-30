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
        /*
         * A SLIDER THAT DOES NOTHING WHILE THE FOLLOW SWITCH IS ON, and until
         * now it did not say so. Its help was the empty string.
         *
         * maxWidthCss() below reads this value ONLY when
         * SiteLayout::get('header_follows') is false, and that switch ships ON.
         * So the ordinary path was: the owner opens Appearance → Header → Bar,
         * finds a control labelled "Content width", drags it, saves, gets
         * "Saved", and the header does not move. Reported exactly that way —
         * "we have this option, but header remains still same width".
         *
         * Nothing was broken underneath: the header really does follow the site
         * width, measured 1200/1200 against the page container at 1280, 1680 and
         * 1920. What was broken was that the screen let him spend his afternoon
         * on the one control that could not win, and said nothing.
         *
         * The help is the honest half. HeaderApiController marks the field inert
         * while the switch is on so the screen can grey it out as well — a note
         * under a slider that still slides is still a trap.
         */
        'max_width'       => ['range',  'Content width', 1280,
                              'Only used when "Header follows the site width" is OFF, on Appearance → Site layout → Page width. While that switch is on, the header is exactly as wide as the page and this number is ignored.',
                              ['min' => 1040, 'max' => 1600, 'step' => 20, 'unit' => 'px']],

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

        /*
         * ── THE FLAG BAR ──────────────────────────────────────────── Lane FB ──
         *
         * The owner: "i need thin bar as same as attached, having uae flat,
         * then text and then korea flag. (This bar is only for mobile, keep
         * this turnef off for desktop by default)."
         *
         * ▲ THESE ARE THE ONE PLACE IN THIS FILE WHERE A NEW DEFAULT IS NOT
         *   "whatever the page does today". CLAUDE.md rule 1 says a new setting
         *   ships at the value the page already has, with one exception — "a
         *   default the owner asked for in as many words" — and the sentence
         *   above is that exception, quoted. `fb_mobile` therefore shipped ON
         *   and `fb_desktop` shipped OFF, which was the whole of what moved
         *   when that package was applied: a 30px strip at the top of the phone
         *   shop, and the desktop shop unchanged by one pixel.
         *
         * ▲ AND HE HAS SINCE CHANGED HIS MIND, IN AS MANY WORDS. (Lane SEC)
         *   "The top countries bar, i need under banner ... apply this on
         *   desktop and mobile both." So the quotation above is history and the
         *   defaults below now read ON and ON. The strip has also MOVED: on the
         *   home page it is drawn under the banner by store/home.blade.php,
         *   which claims it from layouts/store.blade.php, and only on the other
         *   pages is it still the thing above the header this paragraph calls
         *   it. FlagBarUnderBannerTest pins both positions, because the walk in
         *   StorefrontEnglishUnchangedTest structurally cannot see the move —
         *   approvedInsertions() cuts the strip out of the AFTER side wherever
         *   it sits, which is what makes that test green on a page whose strip
         *   has travelled 21 kilobytes down the document.
         *
         * They live in header_settings and not in a module of their own because
         * that is ONE ROW, already read, already memoised, and already loaded by
         * partials/header.blade.php on every page. A settings module of its own
         * would be a second `settings` read on the critical path of every page
         * of the shop to draw thirty pixels — StorefrontQueryBudgetTest is a
         * budget, and this spends none of it.
         *
         * WORDING IS BLANK BY DEFAULT, AND BLANK IS NOT EMPTY. An empty
         * `fb_text` means "use the line this app ships", which is
         * `store.flagbar.text` — a translated key, so /ar renders Arabic the
         * day somebody approves the draft. Typing something here replaces it in
         * BOTH languages, which is the same trade every other operator-typed
         * string on this shop makes (App\Services\SlimFooter's header sets it
         * out) and is the reason the shipped line is a key rather than a
         * default string sitting in this array.
         */
        'fb_mobile'       => ['bool',   'Show it on phones', true,
                              'The thin strip above the header, with the two flags. On, because this is what the bar was asked for.'],
        /*
         * ▲ OFF -> ON, and it is the owner's own sentence that moved it.
         *                                                            (Lane SEC)
         * "The top countries bar, i need under banner ... apply this on desktop
         * and mobile both." The line above this array says `fb_desktop` ships
         * OFF so that nothing above 900px wide changes, which was right when
         * the strip was new and he had not asked for it there. He has now asked
         * for it there in as many words, which is rule 1's one exception, and
         * under the reversed rule 1 it ships on rather than waiting for him to
         * find the switch.
         *
         * A shop that has SAVED the Header screen carries a stored `false` that
         * this default can never overrule, so the migration
         * `banner_ships_as_image_slider` writes the key as well. Both halves,
         * for the reason `kind` needed both.
         */
        'fb_desktop'      => ['bool',   'Show it on desktop', true,
                              'On, so the strip runs across the desktop header too. Turn it off to keep it to phones.'],
        'fb_text'         => ['text',   'Wording', '',
                              'Leave it empty to use the line the shop ships — which is translated, so an Arabic page shows Arabic. Typing here replaces it in every language.'],
        'fb_flags'        => ['bool',   'Show the two flags', true,
                              'The UAE flag at the reading start and the Korean flag at the end. On an Arabic page the pair swaps sides with the text.'],
        'fb_height'       => ['range',  'Bar height', 30,
                              'Reserved in the stylesheet, so the strip takes up its own height before anything has loaded and the header below it never jumps.',
                              ['min' => 22, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'fb_size'         => ['range',  'Text size', 12, '', ['min' => 9, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'fb_flag_h'       => ['range',  'Flag height', 14, 'The width follows: both flags are drawn 3:2, which is their official ratio.',
                              ['min' => 8, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'fb_bg'           => ['colour', 'Background', '#FDEFF4', ''],
        'fb_ink'          => ['colour', 'Text colour', '#E0567B', ''],
        'fb_pill'         => ['bool',   'Outline around the words', true,
                              'The rounded border the words sit inside. Off leaves the line bare on the strip.'],
        'fb_border'       => ['colour', 'Outline colour', '#F0B6C9', ''],
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
        'flagbar' => ['Flag bar', 'The thin strip with the UAE flag, one short line and the Korean flag. On the home page it sits under the banner; on every other page it sits above the header. On for phones and for desktop.',
                      ['fb_mobile', 'fb_desktop', 'fb_text', 'fb_flags', 'fb_height', 'fb_size', 'fb_flag_h',
                       'fb_bg', 'fb_ink', 'fb_pill', 'fb_border']],
    ];

    public function __construct(
        private SettingsService $settings,
        private SiteLayout $layout,
    ) {}

    /**
     * What `--hd-max` is worth on this shop: the page width, or the header's
     * own number.
     *
     * ── WHY THIS DECISION LIVES HERE AND NOWHERE ELSE ───────────────────────
     *
     * `--hd-max` has exactly ONE reader — `header .wrap{max-width:var(--hd-max)}`
     * in kbb.css — and until this method existed it had TWO writers, one of
     * which could never win.
     *
     * Appearance → Site layout → Page width ships a switch, "Header follows the
     * site width", and it wrote `:root{--hd-max:var(--site-max)}` from
     * SiteLayout::cssVariables(). cssVariables() BELOW writes the same property
     * into the `style` attribute of the `<header>` element itself, on every
     * request, whether or not anything has been saved. An inline declaration on
     * the element beats a `:root` declaration outright — that is not a
     * specificity contest it can lose, it is a different and stronger origin —
     * and `.wrap` is a CHILD of `<header>`, so it inherited the inline value and
     * never saw the `:root` one at all.
     *
     * MEASURED IN CHROMIUM, with the switch saved ON, on /shop/:
     *
     *            header .wrap    the page container
     *   1280          1280            1280
     *   1680          1280            1680
     *   1920          1280            1680
     *
     * The switch moved nothing at any width. It was not a subtle failure — it
     * was the whole feature, silently absent, with the admin screen reporting it
     * as on.
     *
     * So the property now has one writer, at the strongest level, and the switch
     * decides what that writer emits. `var(--site-max)` IS THE TOKEN AND NOT THE
     * NUMBER, for the reason SiteWidthSystemTest records: writing `1680px` here
     * looks identical the day it is saved and then freezes, so moving Site width
     * later would leave the header behind with nothing reporting it.
     *
     * Both arms are literals in this file. The only thing a save influences is
     * the integer in the second, which reaches here already clamped to its own
     * slider's range by cast() — rule 5, unchanged.
     */
    private function maxWidthCss(): string
    {
        return $this->layout->get('header_follows')
            ? 'var(--site-max)'
            : $this->all()['max_width'].'px';
    }

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
            // The page width or the header's own number — see maxWidthCss().
            '--hd-max:' . $this->maxWidthCss(),
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
     * ── THE FLAG BAR, in three methods ──────────────────────────────────────
     *
     * Deliberately the same shape as bodyClass() and cssVariables() above: a
     * boolean the template branches on, a class list for the structural
     * choices CSS cannot express, and the appearance as custom properties on
     * the element. The stylesheet is then static and cacheable, and one shop's
     * colours never reach another shop's cache.
     */

    /** Whether the strip is drawn at all. */
    public function flagBarOn(): bool
    {
        $c = $this->all();

        /*
         * BOTH OFF DRAWS NOTHING — not a hidden element, no element. A shop
         * that has switched the strip off on both widths should not be paying
         * for its markup, and a `display:none` strip is still a node in every
         * page of the shop and still in the accessibility tree of any browser
         * that gets the CSS late.
         */
        return (bool) $c['fb_mobile'] || (bool) $c['fb_desktop'];
    }

    /**
     * Which widths draw it, as classes.
     *
     * The two switches are INDEPENDENT rather than one three-way select,
     * because "phones only", "desktop only", "both" and "neither" are four
     * real answers and the owner asked for the first of them. `kfb-m` and
     * `kfb-d` each turn the strip on inside one media query and nothing else
     * turns it on at all, so a strip with neither class is invisible at every
     * width — which is why flagBarOn() above refuses to render one.
     */
    public function flagBarClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['fb_mobile'] ? 'kfb-m' : '',
            $c['fb_desktop'] ? 'kfb-d' : '',
            $c['fb_pill'] ? 'kfb-pill' : '',
        ])));
    }

    /**
     * The strip's appearance, as custom properties.
     *
     * It sits OUTSIDE <header>, so it cannot inherit the properties
     * cssVariables() puts on that element and carries its own five. Every
     * colour here has been through ModuleSchema::cast() with this class's
     * POLICY, whose `hex` axis is `strict` — Color::isValidHex() or the
     * schema default, never the stored string — so nothing that is not a hex
     * colour can reach a style attribute from here.
     */
    public function flagBarStyle(): string
    {
        $c = $this->all();

        return implode(';', [
            '--kfb-h:' . (int) $c['fb_height'] . 'px',
            '--kfb-s:' . (int) $c['fb_size'] . 'px',
            '--kfb-fh:' . (int) $c['fb_flag_h'] . 'px',
            '--kfb-bg:' . $c['fb_bg'],
            '--kfb-ink:' . $c['fb_ink'],
            '--kfb-bd:' . $c['fb_border'],
        ]);
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
