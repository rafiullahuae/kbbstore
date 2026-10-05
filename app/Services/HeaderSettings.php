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
        'logo_accent_col' => ['colour', 'Accent colour', '#C6395F', ''],

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

        // ── Search: sets first (1 October 2026, the owner: "the search is not
        //    showing set products at all ... if i write Anua, any set which has
        //    Anua in it should display #1; if multiple, random on every search").
        //    ▲ SHIPS ON: he asked for it (CLAUDE.md, 30-September reversal).
        'search_sets_first' => ['bool', 'Sets first', true,
                                'When a search names a brand, or matches a set by name, one set is shown at the top of the results. A set counts for a brand when it is that brand\'s own or has one of its products in the box.'],
        'search_sets_pick'  => ['select', 'Which set comes first', 'random',
                                'When several sets fit the search.',
                                ['random' => 'A different one each search', 'best' => 'Always the best-selling set']],

        // ── Search: styles & colours ──
        'search_style_accent'      => ['colour', 'Accent colour', '#C6395F', 'Prices, the view-all button, active states.'],
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
        'badge_bg'        => ['colour', 'Count badge', '#C6395F', ''],

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

        /*
         * ── FIT THE MENU TO THE ROW ───────────────────────────────── Lane NV ──
         *
         * The owner, 4 October, over a screenshot of the twelve-entry desktop
         * menu with a wide empty stretch at the end of the row: "the top desktop
         * menu items font size should auto adjust if empty area there. i mean
         * enlarge, and if more items, then reduce the font size itself to adjust
         * all the parents menus items ... this formula will apply only if the
         * menu item has at least 9 menu items. less than that it will display
         * as it is."
         *
         * ▲ `nav_fit` SHIPS ON, WHICH IS A MOVED DEFAULT AND HIS (CLAUDE.md,
         *   the 30-September reversal). The switch is here so he can put the
         *   row back exactly as it was in one press.
         *
         * A menu with fewer top-level items than `nav_fit_from` renders byte for
         * byte as it did before this lane; App\Support\NavRowFit decides, and
         * partials/nav-bar.blade.php prints nothing new when it says no.
         *
         * All three sizes are SELECTS of fixed integers, so every value that
         * reaches the `style` attribute is one of this file's own option keys
         * (rule 5) and the smallest option of `nav_fit_max` is above the largest
         * of `nav_fit_min` — the clamp can never be inverted by a save.
         */
        'nav_fit'         => ['bool',   'Fit the menu to the row', true,
                              'Desktop only. With enough items in the menu, the row is filled edge to edge (see How it fills the row), and the text shrinks if needed so every item stays on one line. Off shows the menu at its normal size.'],
        /*
         * 2.60.376. The owner, on 2.60.375's fitted menu: "give control to set
         * menu font size upon expanding. because it coming too big, i want to
         * keep the font size control but the space will auto adjust as per the
         * number of parents items." So by default the text stays at Navigation
         * → Text size (`nav_size`) and the row is filled with space between the
         * items; growing the text is the other choice. It still SHRINKS when the
         * items cannot fit at that size, so the menu never wraps.
         */
        'nav_fit_mode'    => ['select', 'How it fills the row', 'space',
                              'Spread the items: the text stays at Text size above and the space between the items grows to fill the row. Bigger text: the text grows up to Largest text size. Either way the text gets smaller if the items would not fit on one line.',
                              ['space' => 'Spread the items (keep my text size)', 'text' => 'Bigger text']],
        'nav_fit_from'    => ['select', 'Fit it from', '9',
                              'How many top-level items the menu needs before it is fitted. A shorter menu shows exactly as it always has.',
                              ['6' => '6 items', '7' => '7 items', '8' => '8 items', '9' => '9 items', '10' => '10 items',
                               '11' => '11 items', '12' => '12 items', '14' => '14 items', '16' => '16 items']],
        'nav_fit_min'     => ['select', 'Smallest text size', '10',
                              'The text never shrinks below this, however many items there are.',
                              ['9' => '9px', '10' => '10px', '11' => '11px', '12' => '12px', '13' => '13px']],
        'nav_fit_max'     => ['select', 'Largest text size', '18',
                              'Used when How it fills the row is Bigger text: the text never grows past this, and any room left over is shared out evenly between the items.',
                              ['14' => '14px', '15' => '15px', '16' => '16px', '17' => '17px', '18' => '18px', '19' => '19px', '20' => '20px']],

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
         * ▲ AND NOW OFF ON BOTH, IN HIS WORDS AGAIN.                (Lane PI-B)
         *   "Turn off the top countries bar entirely for now." So `fb_mobile`
         *   and `fb_desktop` below both ship `false` — the third time these
         *   two defaults have moved, and each time it was his sentence that
         *   moved them. "Entirely" is why it is BOTH switches rather than one,
         *   and "for now" is why it is the switches rather than the markup:
         *   every control on Appearance → Header → Flag bar stays where it is,
         *   and one press on either switch puts the strip back exactly as it
         *   was. flagBarOn() answers false with both off, so the strip is not a
         *   hidden element on any page — it is no element, on the home page
         *   (under the banner) and on every other page (above the header).
         *   A shop whose Header screen has been SAVED carries stored `true`s
         *   these defaults cannot overrule, so the migration
         *   2027_07_06_000000_flag_bar_ships_off writes both keys too — the
         *   same two halves `banner_ships_as_image_slider` needed.
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
        'fb_mobile'       => ['bool',   'Show it on phones', false,
                              'The thin strip with the two flags. Off, because you asked for the countries bar to be turned off for now. Switch it on to bring it back on phones exactly as it was.'],
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
         *
         * ▲ ON -> OFF, and his sentence again (Lane PI-B): "Turn off the top
         *   countries bar entirely for now." See the note at the top of this
         *   block, and the migration `flag_bar_ships_off`.
         */
        'fb_desktop'      => ['bool',   'Show it on desktop', false,
                              'Off, because you asked for the countries bar to be turned off for now. Switch it on to bring the strip back across the desktop header.'],
        'fb_text'         => ['text',   'Wording', '',
                              'Leave it empty to use the line the shop ships — which is translated, so an Arabic page shows Arabic. Typing here replaces it in every language.'],
        /*
         * ── THE WORDS COME OFF THE DESKTOP STRIP ──────────────── Lane BG ──
         *
         * The owner, whole: *"UAE's Authentic K-Beauty Store — remove this from
         * the desktop version."*
         *
         * He quoted the LINE, not the bar, so the bar stays: on desktop the
         * strip keeps its two flags and loses the words, and on phones nothing
         * changes at all. `fb_desktop` is deliberately untouched — turning that
         * off would take the flags with it, which is the other reading of his
         * sentence and not the one he wrote.
         *
         * ▲ IT SHIPS AT `false`, WHICH IS A MOVED DEFAULT. CLAUDE.md's
         *   30-September reversal: a thing he asked for is the shop's new
         *   state, not a switch he has to go and find. The control is here so
         *   he can put the line back in one press.
         *
         * ── WHY A SWITCH AND NOT A SECOND `fb_text` FOR DESKTOP ────────────
         *
         * `fb_text` is ONE string shared by both widths. Emptying it, or
         * giving desktop its own empty one, would strip the line from phones
         * too — and "on phones nothing changes" is half of what he asked for.
         * A boolean is the smallest thing that can be device-scoped without
         * touching the wording he may still want on a phone.
         *
         * ── AND WHY THE MECHANISM IS A CLASS RATHER THAN NOT RENDERING IT ──
         *
         * This is the one decision worth reading. The strip is ONE ELEMENT in
         * ONE DOCUMENT: `flagBarClass()` puts both `kfb-m` and `kfb-d` on it
         * and the stylesheet's two media queries decide which width sees it.
         * There is no desktop document and no phone document — this shop
         * serves the same bytes to both, deliberately, because a cached page
         * has to stay correct on every device and because sniffing the user
         * agent is the thing this codebase refuses to do (see
         * HomepageSections' own header on exactly that).
         *
         * So the words CANNOT be left out of the markup on desktop without
         * also leaving them out on phones, which is the requirement inverted.
         * The honest answer is a class the desktop media query reads, and the
         * cost is named rather than hidden: the line stays in the HTML source,
         * where a crawler can read it. What it does NOT stay in is the
         * ACCESSIBILITY TREE — `display:none` removes an element from it
         * outright, so a desktop screen-reader user hears the flags and no
         * stray sentence, which is the half that would actually have been a
         * defect.
         */
        'fb_text_desktop' => ['bool',   'Show the wording on desktop', false,
                              'Off, so the desktop strip is the two flags and nothing between them — you asked for the line to come off there. Phones are not affected either way; the wording above is what they show.'],
        'fb_flags'        => ['bool',   'Show the two flags', true,
                              'The UAE flag at the reading start and the Korean flag at the end. On an Arabic page the pair swaps sides with the text.'],
        /*
         * ▲ (Lane HC) 30→46px, 12→14px text, 14→20px flags and dark words in
         *   place of rose: the owner's screenshot of the old shop's countries
         *   strip — "same design, same height, same text" — which is now the
         *   homepage's `countries` section, phones only. The strip stays OFF on
         *   every other page (fb_mobile / fb_desktop above are unchanged), so
         *   these sizes reach a shopper only where he asked for them.
         */
        'fb_height'       => ['range',  'Bar height', 46,
                              'Reserved in the stylesheet, so the strip takes up its own height before anything has loaded and the header below it never jumps.',
                              ['min' => 22, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'fb_size'         => ['range',  'Text size', 14, '', ['min' => 9, 'max' => 18, 'step' => 1, 'unit' => 'px']],
        'fb_flag_h'       => ['range',  'Flag height', 20, 'The width follows: both flags are drawn 3:2, which is their official ratio.',
                              ['min' => 8, 'max' => 28, 'step' => 1, 'unit' => 'px']],
        'fb_bg'           => ['colour', 'Background', '#FDEFF4', ''],
        'fb_ink'          => ['colour', 'Text colour', '#3B2730', ''],
        'fb_pill'         => ['bool',   'Outline around the words', true,
                              'The rounded border the words sit inside. Off leaves the line bare on the strip.'],
        'fb_border'       => ['colour', 'Outline colour', '#F0B6C9', ''],

        /*
         * ── THE BREADCRUMB TRAIL ───────────────────────────────── Lane PI-B ──
         *
         * The line under the header — "Home / Super Sale / Medicube – PDRN
         * Glow Booster Set (Pink Edition)" — on a product page, a category,
         * /shop, a brand, a journal article, the wishlist, a content page and
         * the routines pages. The owner wanted its spacing (above and below)
         * and an on/off, SEPARATELY for phones and desktop, "by default keep
         * it off".
         *
         * ▲ BOTH SWITCHES SHIP `false`, WHICH IS A MOVED DEFAULT AND HIS. The
         *   trail has been on every one of those pages since they were built;
         *   CLAUDE.md's 30-September reversal makes what he asked for the
         *   shop's new state rather than a switch to go and find. The four
         *   sliders ship at the product page's own numbers today (18px above,
         *   nothing below — kbb-product.css's `.crumb{padding:18px 0 0}`), so
         *   switching a device back on returns the page he named to exactly
         *   how it looked.
         *
         * ── WHY HERE, AND WHY CSS ───────────────────────────────────────────
         *
         * HERE for the reason the flag bar's keys are here: `header_settings`
         * is ONE row that partials/header.blade.php has already read and
         * memoised on every page, so these six cost no query.
         * StorefrontQueryBudgetTest is a budget. And the trail is the first
         * thing under the header, so Appearance → Header is where he looks.
         *
         * CSS, NOT LEAVING IT OUT, for the reason `kfb-notx` gives: this shop
         * serves one document to every device, so "off on phones, on on
         * desktop" can only be a media query. breadcrumbCss() writes
         * `display:none` for an off device — out of the layout and out of
         * the accessibility tree, so no empty gap stays behind — and the
         * owner's spacing for an on one. The BreadcrumbList JSON-LD is printed
         * by App\Support\Seo from the controllers, not from this markup, so
         * hiding the visible trail leaves the structured data untouched.
         */
        'bc_mobile'       => ['bool',   'Show it on phones', false,
                              'Off, because you asked for the breadcrumb to be off by default. Search engines still get the trail either way — it is in the page’s structured data, not in this line.'],
        'bc_desktop'      => ['bool',   'Show it on desktop', false,
                              'Off by default too. The two switches are independent: phones off and desktop on is allowed.'],
        'bc_above_mobile' => ['range',  'Space above · phone', 18,
                              'Between the header and the trail, on screens up to 900px wide.',
                              ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'bc_below_mobile' => ['range',  'Space below · phone', 0,
                              'Between the trail and the page underneath it.',
                              ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'bc_above'        => ['range',  'Space above · desktop', 18,
                              'Between the header and the trail, on screens wider than 900px.',
                              ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
        'bc_below'        => ['range',  'Space below · desktop', 0,
                              'Between the trail and the page underneath it.',
                              ['min' => 0, 'max' => 48, 'step' => 1, 'unit' => 'px']],
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
                      ['nav_show', 'nav_uppercase', 'nav_size', 'nav_gap', 'nav_hot_colour', 'nav_fit', 'nav_fit_mode', 'nav_fit_from', 'nav_fit_min', 'nav_fit_max']],
        'support' => ['Support', 'The WhatsApp block.',
                      ['support_show', 'support_label', 'support_icon_bg', 'support_icon_fg']],
        'flagbar' => ['Flag bar', 'The thin strip with the UAE flag, one short line and the Korean flag. On the home page it sits under the banner; on every other page it sits above the header. Off on phones and on desktop for now — switch either one on to bring it back, with the wording on phones only.',
                      ['fb_mobile', 'fb_desktop', 'fb_text', 'fb_text_desktop', 'fb_flags', 'fb_height', 'fb_size', 'fb_flag_h',
                       'fb_bg', 'fb_ink', 'fb_pill', 'fb_border']],
        'crumbs'  => ['Breadcrumbs', 'The "Home / Category / Product" line under the header, on product, category, shop, brand, article, wishlist and content pages. Off on phones and desktop by default; each width has its own switch and its own spacing.',
                      ['bc_mobile', 'bc_desktop', 'bc_above_mobile', 'bc_below_mobile', 'bc_above', 'bc_below']],
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
    public function flagBarClass(?bool $mobile = null, ?bool $desktop = null): string
    {
        $c = $this->all();

        /*
         * Lane HC: the HOMEPAGE passes its own two switches — the strip is the
         * `countries` section there, shown per device by its Homepage row like
         * every other section — and every other page passes none and reads the
         * Flag bar's own `fb_mobile` / `fb_desktop`, exactly as before.
         */
        return trim(implode(' ', array_filter([
            ($mobile ?? $c['fb_mobile']) ? 'kfb-m' : '',
            ($desktop ?? $c['fb_desktop']) ? 'kfb-d' : '',
            $c['fb_pill'] ? 'kfb-pill' : '',
            /*
             * ── EMITTED FOR THE OFF STATE, WHICH IS THE ONE THAT SHIPS ──
             *                                                    (Lane BG)
             * The other three above name what is ON. This one names what is
             * OFF, and that is a decision rather than an inconsistency: the
             * stylesheet's BASELINE is the strip as it has always drawn —
             * words at every width — and `kfb-notx` is the only thing that
             * takes them off. Written the other way round, the baseline would
             * have had to become "no words on desktop" and a shop that turns
             * the line back on would depend on a second rule overriding the
             * first, in a media query, by source order.
             *
             * Additive is also what makes it reviewable: not one existing
             * declaration in the `.kfb` block moves, so the phone rendering
             * is byte-identical by construction rather than by assertion.
             */
            $c['fb_text_desktop'] ? '' : 'kfb-notx',
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
     * Every element on the storefront that draws the breadcrumb trail.
     *
     * `.crumb` is the product page, a category and /shop (one view), the
     * wishlist, a journal article and a content page; `.brw-crumb` is the brand
     * index and a brand page; `.rtn-crumb` is the routines pages. A new page
     * that draws a trail under one of these names is covered without anything
     * here changing; one under a new name has to be added here, and
     * BreadcrumbControlsTest walks the storefront views to say so.
     */
    public const CRUMB_SELECTORS = ['.crumb', '.brw-crumb', '.rtn-crumb'];

    /**
     * The breadcrumb's switches and spacing, as one small stylesheet.
     *
     * PRINTED ESCAPED, and it can be: every selector, property and piece of
     * punctuation below is a literal in this method, and the only thing a
     * saved value can reach is an integer that cast() has already clamped to
     * its own slider's 0–48 and that is cast again here. Not one of the
     * characters htmlspecialchars() rewrites (& < > " ') appears in it, so
     * `{{ }}` prints it byte for byte — partials/breadcrumb-css.blade.php does,
     * and BreadcrumbControlsTest pins that the two are the same string.
     *
     * ── THE SPECIFICITY IS CHOSEN, NOT INCIDENTAL ───────────────────────────
     *
     * `:root body :is(…)` is (0,2,1). The page sheets declare the trail's
     * spacing at (0,1,0) — `.crumb{padding:18px 0 0}` in kbb-product.css,
     * `.crumb{padding:14px 0 6px}` inside kbb-shop.css's phone query — and
     * kbb.css has one at (0,2,0), `.kbb-home .crumb{margin-bottom:16px}`. All
     * of them lose to this wherever this sits in the document, so the
     * owner's numbers are the trail's whole spacing without an `!important`
     * and without depending on which stylesheet the page linked last.
     *
     * Margins are zeroed and the spacing is padding: the trail's own box then
     * IS the space he asked for, and a margin cannot collapse into the
     * heading's below it and quietly eat his number.
     *
     * ── 900 / 901, THE SHOP'S ONE BREAKPOINT ────────────────────────────────
     *
     * The same pair the flag bar's `kfb-m` / `kfb-d` and every phone rule in
     * kbb.css use, so "phone" here means what it means everywhere else on
     * this shop. No JavaScript, nothing measured.
     */
    public function breadcrumbCss(): string
    {
        $c = $this->all();
        $sel = ':root body :is(' . implode(',', self::CRUMB_SELECTORS) . ')';

        $device = static function (string $query, bool $on, int $above, int $below) use ($sel): string {
            if ($on) {
                return '@media ' . $query . '{' . $sel . '{margin-top:0;margin-bottom:0;padding-top:'
                    . $above . 'px;padding-bottom:' . $below . 'px}}';
            }

            /*
             * OFF, AND THE HEADING BELOW IT KEEPS ITS AIR. On /shop and the
             * collection pages the trail was the only thing between the header
             * and the page heading, so hiding it left "K-BEAUTY · SKINCARE" 6px
             * under the menu -- measured, and visibly cramped at 1280. The
             * heading that follows a hidden trail gets the same "space above"
             * this device's slider holds (18px by default), so the gap stays
             * the owner's to set. Only listing headings (.eyebrow on /shop, .sh
             * on collections): the product page already sits 22px down on its
             * own padding and is left as it is.
             */
            return '@media ' . $query . '{' . $sel . '{display:none}'
                . $sel . '+:is(.eyebrow,.sh){margin-top:' . $above . 'px}}';
        };

        return $device('(max-width:900px)', (bool) $c['bc_mobile'], (int) $c['bc_above_mobile'], (int) $c['bc_below_mobile'])
             . $device('(min-width:901px)', (bool) $c['bc_desktop'], (int) $c['bc_above'], (int) $c['bc_below']);
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
