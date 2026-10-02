<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The site width, the side gutter, and the number of product columns.
 *
 * ── WHAT THE OWNER ASKED FOR ────────────────────────────────────────────────
 *
 * "site width max i need 1680 px, but it must be auto adjust in below width
 * screens, and for mobile is fine. please make super strong options and
 * features for this. the site should fit on any kind of device automatically,
 * and on 1680px the grid products will show 1 column extra, and in low, one
 * less and so on, give options to control also for the whole layout."
 *
 * ── WHAT WAS ACTUALLY THERE, MEASURED BEFORE ANYTHING WAS DESIGNED ──────────
 *
 * There was no site width to change. A census of every `max-width:<n>px` in
 * resources/views/{store,components,partials,layouts} and resources/css/kbb
 * found 217 of them — and the first correction is that 142 are MEDIA-QUERY
 * BREAKPOINTS, not widths. Of the 75 element widths (29 distinct values),
 * FOURTEEN are page containers and they disagreed six ways:
 *
 *     1400px   kbb.css `.wrap`, declared TWICE — and a third, dead,
 *              `.wrap{max-width:1200px}` two thousand lines above it, same
 *              selector, same specificity, silently overridden
 *     1352px   kbb.css `.kbb-home .sec > .wrap`, the home page
 *     1240px   kbb-shop.css `.wrap`, /shop
 *     1180px   kbb-product.css `.wrap`, a product page; store/brands `.brw`
 *     1160px   store/blog and store/post `.wrap`, the Journal and an article
 *     1080px   store/review-wall `.page`; store/routines `.rtn-wrap`
 *     1040px   kbb-cart / kbb-account `.wrap`; the checkout's `.co-grid`
 *     1280px   the HEADER, on its own `--hd-max`, which is a real setting
 *
 * The other sixty-one element widths are MEASURES and are not on this screen:
 * a 720px article column, a 440px form, a 340px card, 62ch of prose. A measure
 * is not a site width. Widening a paragraph to 1680px does not make the shop
 * wider, it makes it unreadable, so those keep the literal values they have
 * always had and nothing here touches them — see the note in kbb.css's :root for
 * why they are documented there rather than turned into tokens nothing reads.
 * THAT DISTINCTION IS THE POINT OF THIS CLASS, more than the number is.
 *
 * ── WHAT THIS SCREEN DOES NOT GOVERN, AND WHY NOT ───────────────────────────
 *
 *   the cart page   `--cpg-d-max`, CartPage's own `d_max`, default 1200
 *   the checkout    `--cop-d-max`, CheckoutPage's own `d_max`, default 1040
 *   the slim footer `--sf-max`, SlimFooter's own `max_w`, default 1240
 *
 * All three already have a width slider of their own on their own screen, and
 * all three are pages asking for money: a narrow single-column ledger is a
 * deliberate decision about conversion, not an accident of a stale number.
 * Folding them in would have widened the checkout to 1680px on every shop that
 * applies the package, which nobody asked for. They are listed here so the
 * next reader knows they were considered rather than missed.
 *
 * ── HOW THE COLUMN COUNT IS DECIDED, AND WHY NOT BY A LADDER ────────────────
 *
 * Before this there were FOUR independent column systems and they disagreed
 * with each other at the same viewport width. Measured in Chromium on a seeded
 * shop, at 1180px the homepage rails showed 3 columns and /shop showed 4; at
 * 834px the rails showed 2 and /shop showed 3. Ten media queries across three
 * stylesheets, each with its own breakpoints.
 *
 * The count comes from `repeat(auto-fill, …)` now — see --kbb-track in
 * kbb.css, which is the one declaration all of them share. `auto-fill` derives
 * the count from the GRID'S OWN ROW rather than from the window, and that is
 * not tidiness: /shop's grid sits beside a 250px filter rail and a 28px gap,
 * so at a 1280px viewport it has 962px to work in. Its old ladder keyed off
 * the viewport, so at 1180 it declared four columns for a row with 852px in
 * it — 200px a tile. A viewport breakpoint cannot know about the rail.
 * `100%` inside `grid-template-columns` cannot NOT know about it.
 *
 * So the number the owner sets here is a TILE MINIMUM, not a count. It shipped
 * at 260px, the value that reproduced the four-column shop W1 inherited.
 *
 * ▲ IT SHIPS AT 220px NOW, AND THAT IS A DEFAULT THE OWNER ASKED FOR IN AS MANY
 * WORDS: "by default 5 columns on desktop and 2 columns on mobile".     Lane PG
 *
 * Nothing about the mechanism changed — the count is still derived from the row
 * by one declaration with no breakpoint in it. 220 is the tile minimum that
 * gives FIVE columns and not six in the 1203px row a 1280px screen has, and the
 * phone's two come from `cols_floor` exactly as before. The full derived ladder,
 * and the arithmetic behind the number, are in the :root comment in
 * resources/css/kbb/kbb.css beside the declaration itself, so the default and
 * its reason cannot drift apart. SiteLayoutDefaultsMatchCssTest pins that the
 * two copies agree.
 *
 * ── WHAT SHIPS CHANGED, AND IT IS EXACTLY TWO THINGS ────────────────────────
 *
 * `max` ships at 1680px. That is a real change to the rendered shop on the
 * home page (1352 → 1680), /shop (1240 → 1680), a product page (1180 → 1680)
 * and the Journal (1160 → 1680), and it is the default the owner asked for in
 * as many words. It is called out in the commit rather than buried.
 *
 * `header_follows` SHIPS ON, which is the second, and it is the same kind of
 * exception for the same reason.                                     Lane H1
 *
 * It shipped OFF, so that applying the width package moved the page and left
 * the header at 1280px. The owner's next sentence was "the header need to be
 * matched the width", which is this switch in as many words, so it ships at the
 * value he asked for rather than at the value the page had. Measured on /shop/:
 * the header container goes 1280 → 1366 at a 1366px viewport, 1280 → 1680 at
 * 1680 and above, and is unchanged at 1280 and below because there the page
 * container is the viewport too. Called out in the commit and in
 * 2027_02_14_000000_clear_caches_desktop_header_width, not buried.
 *
 * Turning it back off is one click on the screen below and restores 1280px
 * exactly.
 *
 * EVERY OTHER SETTING HERE SHIPS AT THE VALUE THE PAGE ALREADY HAD: the
 * gutter at 22px (kbb.css's generic `.wrap` padding), the tile at 260px, the
 * column floor at 2 (what the phone already showed), the cap at 8 (which is
 * more columns than --kbb-tile will ever allow, so it is inert until moved),
 * the gap at 16px and the pin at `auto`.
 */
class SiteLayout
{
    /**
     * ── THE SCHEMA ───────────────────────────────────────────────────────────
     *
     * Positional `[type, label, default, help, options]`, which is the form
     * every schema in this app is written in and which ModuleSchema::field()
     * widens. `store` is not declared per field because every key here lives
     * in the global `settings` table and is written by THIS module's own
     * endpoint — see STORE below, which normalise() applies to all of them.
     */
    public const SCHEMA = [
        // ── Page width ──
        'max' => ['range', 'Site width', 1680,
            'The widest the page ever gets. Below it the page is the screen less its gutters, so there is no width at which this is undefined — that is what "fits any device" means here.',
            ['min' => 1040, 'max' => 2400, 'step' => 20, 'unit' => 'px']],
        'gutter' => ['range', 'Side gutter', 22,
            'The space between the content and the edge of the screen. This is the narrow end.',
            ['min' => 8, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'gutter_wide' => ['range', 'Side gutter · wide screens', 22,
            'The wide end. Equal to the one above means a constant gutter, which is how it ships.',
            ['min' => 8, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'header_follows' => ['bool', 'Header follows the site width', true,
            'On: the header is exactly as wide as the page. Off: the header keeps its own Content width from Appearance → Header, which is 1280px.'],

        // ── Product grid ──
        'tile' => ['range', 'Smallest card', 220,
            'The column count is worked out from this and the width the grid actually has. Smaller means more columns, sooner.',
            ['min' => 120, 'max' => 420, 'step' => 10, 'unit' => 'px']],
        'tile_shop' => ['range', 'Smallest card · shop listing', 220,
            'The /shop and category listing has a filter rail beside it, so its row is narrower than the page at the same screen size — 962px against 1203px at 1280. It needs its own number; one value cannot keep today\'s four columns on both.',
            ['min' => 120, 'max' => 420, 'step' => 10, 'unit' => 'px']],
        'cols_floor' => ['range', 'Never fewer than', 2,
            'Held even when the cards would be narrower than this allows — which is what keeps two cards on a 320px phone.',
            ['min' => 1, 'max' => 3, 'step' => 1, 'unit' => ' columns']],
        'cols_cap' => ['range', 'Never more than', 8,
            'A ceiling on the automatic answer. Eight is more than the smallest card will ever allow, so it does nothing until you lower it.',
            ['min' => 2, 'max' => 8, 'step' => 1, 'unit' => ' columns']],
        'gap' => ['range', 'Gap between cards', 16,
            'Applies to the skinnable grid — the homepage rails, a category, the wishlist, a brand page and the [kbb_products] shortcode. The /shop listing and the related row keep their own 18px.',
            ['min' => 6, 'max' => 32, 'step' => 2, 'unit' => 'px']],
        'pin' => ['select', 'Or pin an exact count', 'auto',
            'Overrides the automatic answer everywhere except a phone, which keeps the floor above. The /shop listing always obeys the shopper\'s own 2 / 3 / 4 buttons instead.',
            [
                'auto' => 'Automatic — follow the width',
                '2' => '2 columns', '3' => '3 columns', '4' => '4 columns',
                '5' => '5 columns', '6' => '6 columns', '7' => '7 columns', '8' => '8 columns',
            ]],

        /*
         * ── LOADING MORE PRODUCTS ──────────────────────────────── Lane PI-B ──
         *
         * The owner asked for a choice of how a listing loads more: "Arrows"
         * (numbered pages — what the shop does today), "Load more on scroll"
         * (batches of 12, 15, 20 or a number he types) or "Load all". He did
         * not say which should be the default, so it shipped at `arrows`, the
         * page as it was — CLAUDE.md rule 1. The arrows themselves were broken
         * on the four curated listings (Laravel's Tailwind pager, with an
         * unsized SVG, on a shop with no Tailwind) and are fixed regardless of
         * this setting; see partials/listing-pager.blade.php.
         *
         * Read by ShopController (/shop/ and every category) and
         * CollectionController (/new-in/, /best-sellers/, /super-sale/,
         * /everything-under-54-aed/ and the concern pages) through perPage().
         * NOT a stylesheet value: isDefault() and css() skip these three, so a
         * choice here never puts a byte of CSS on the page.
         *
         * ▲ IT SHIPS AT `scroll` NOW, AND THE OWNER ASKED FOR IT IN AS MANY
         *   WORDS.                                                  (Lane PR)
         *
         * "Remove pagination from the categories and brands; it should load
         *  more products via scroll with grey loading stuff. We have built it
         *  already, just keep this on by default, the products should load
         *  automatically by default upon scroll."
         *
         * So /shop/, every category, the curated listings AND a brand's own
         * page (/brands/{slug}/, which Store\BrandController::show() now reads
         * through perPage() as well — it was a fixed preview of twelve with no
         * way past them) open on one batch and bring the next as the shopper
         * nears the end, with the grey placeholders while it comes. The arrows
         * are still the markup underneath, so a shopper without JavaScript —
         * and a crawler — still pages through every product. "Arrows" is one
         * click away on the same screen. 2027_07_11_000000_clear_caches_lane_
         * pr_grid deletes a stored `layout_load_mode` row so this default is
         * what the shop actually gets.
         */
        'load_mode' => ['select', 'How more products load', 'scroll',
            'Load more on scroll, the default: the next batch appears as the shopper nears the end of the grid, with grey placeholders while it comes. Arrows: numbered pages only. Load all: every product in one page, up to '.self::LOAD_ALL_CAP.' — past that the arrows take over, so a huge catalogue cannot become one enormous page.',
            [
                'arrows' => 'Arrows — numbered pages',
                'scroll' => 'Load more on scroll',
                'all' => 'Load all on one page',
            ]],
        'load_batch' => ['select', 'Products per batch', '12',
            'Only used by "Load more on scroll": how many products each batch brings, the first screenful included.',
            [
                '12' => '12', '15' => '15', '20' => '20',
                'custom' => 'My own number',
            ]],
        'load_batch_custom' => ['int', 'My own number', 24,
            'Only used when "Products per batch" is "My own number". A whole number from 4 to 96; anything above or below is pulled to the nearest end, and anything that is not a number is refused.',
            ['min' => self::BATCH_MIN, 'max' => self::BATCH_MAX, 'step' => 1, 'unit' => '']],

        /*
         * ── THE CATEGORY TITLE HEADER ───────────────────────────────── Lane PT ──
         *
         * The owner: "We have a banner image on each category on the old site.
         * Need to bring that on the category pages as title background, like
         * on /sunscreens/ -- and the same title, same description." He asked
         * for it, so it SHIPS ON (CLAUDE.md, 30 September).
         *
         * ── AND ON EVERY CATEGORY, PICTURE OR NOT ──────────────── Lane PY ──
         *
         * The owner again, on PT's preview: "by default make the title name
         * left side as before, i just wanted the background image or light
         * colored box containing skincare makeup etc products icons. if no
         * image. ... i can be able to update that background iamge, title font
         * size etc and description etc for each category. and will have
         * control of overall section height padding etc. and some type of
         * shadow behind the name ... please give me multiple options to
         * chooose from."
         *
         * So, as shipped, and every one of these is one click away:
         *
         *   - alignment START (left in English, right in Arabic) -- he said so;
         *   - a category with no picture gets the LIGHT BOX ("Blush icons", the
         *     shop's own pink) instead of the plain title -- he asked for it;
         *   - over a picture the title has a SOFT SHADOW plus the darkening PT
         *     shipped -- "some type of shadow behind the name";
         *   - on the light box the text is dark with no shadow, which is what
         *     reads on a pale ground.
         *
         * Those four defaults were CHOSEN BY THE INTEGRATOR from his words; he
         * has not picked a letter yet, and docs/py-options/overview.png shows
         * him every one. Brand pages are NOT in his request: a brand keeps
         * 2.60.346's behaviour (a header only when it has a picture) and the
         * brand light box is a separate switch that ships OFF.
         *
         * Read by App\Support\TitleHeader only. NOT a stylesheet value:
         * isDefault() and css() skip these, so none of it puts a byte of CSS
         * on any page; the numbers ride on the header element itself, as
         * clamped integers in properties whose names are constants there, and
         * the two colours are hex validated by this screen's `expand` dialect
         * (#rgb or #rrggbb, nothing else) before they are stored.
         */
        'cat_header' => ['bool', 'Show the header on category pages', true,
            'On: every category page opens with its title and description in a header -- over the category\'s picture when it has one, or in the light box below when it has none. Off: every category page shows the plain title, as before.'],
        'cat_header_box' => ['bool', 'When a category has no picture, show a light box', true,
            'On: a category with no header picture gets a soft-coloured box behind its title (choose the look below). Off: such a category shows the plain title, as before.'],
        'cat_header_fallback' => ['bool', 'When no banner was imported, use the category picture', false,
            'Off: only a header picture (imported, or chosen in Catalog → Categories → Edit → Category header) is used. On: a category with none uses its own square category picture instead.'],
        'cat_header_brands' => ['bool', 'Brand pages too (when the brand has a picture)', true,
            'The same header on a brand\'s page when the brand\'s import carried a banner.'],
        'cat_header_box_brands' => ['bool', 'Light box on brand pages with no picture', false,
            'Off, as shipped: a brand with no banner keeps its page exactly as it is. On: it gets the same light box as a category.'],
        'cat_header_align' => ['select', 'Text alignment', 'start',
            'Start is the left edge on the English shop and the right edge on the Arabic one -- where the title sat before. A category can choose its own in Catalog → Categories.',
            ['start' => 'Start (left in English, right in Arabic)', 'center' => 'Centred', 'end' => 'End (right in English, left in Arabic)']],
        'cat_header_treatment' => ['select', 'Keep the words readable · on a picture', 'shadow',
            'What sits behind the title and description over a picture, so they never merge into it. Works together with "Darken the picture".',
            self::TREATMENTS],
        'cat_header_box_treatment' => ['select', 'Keep the words readable · on the light box', 'none',
            'The same choice on the light box. A pale box needs nothing, so it ships at None.',
            self::TREATMENTS],
        'cat_header_overlay' => ['range', 'Darken the picture', 40,
            'How much the picture is darkened (or, with dark text, lightened) so the words stay readable.',
            ['min' => 0, 'max' => 85, 'step' => 5, 'unit' => '%']],
        'cat_header_box_style' => ['select', 'Light box style', 'blush',
            'The look of the box a category with no picture gets. A, B, C and D carry a soft pattern of beauty-product line icons; E is the plain colour below; F uses both colours below.',
            self::BOX_STYLES],
        'cat_header_box_bg' => ['colour', 'Box colour · E and F', '#FFF4EE',
            'Used by "Plain soft colour" and "My own colours". A hex colour such as #FFF4EE or #FEF.'],
        'cat_header_box_icon' => ['colour', 'Icon colour · F', '#EFA889',
            'The colour of the icons for "My own colours". Keep it close to the box colour so the icons stay a pattern, not a picture.'],
        'cat_header_text' => ['select', 'Text colour', 'auto',
            'Automatic: white over a picture, dark on the light box (and white on a dark box colour of your own).',
            ['auto' => 'Automatic', 'light' => 'White', 'dark' => 'Dark']],
        /*
         * 2.60.350 -- the owner, on every category header reading "hide":
         * "i wanted the category name, with 2 lines description, that's it,
         * if no description set from backend, then generic line should come.
         * Find your favorite products in our wide range <category name>
         * category.. the content will come left bottom with spacing controls".
         * Both ship ON as he asked.
         */
        'cat_header_valign' => ['select', 'Where the words sit, top to bottom', 'bottom',
            'Bottom, as asked: the title and description sit at the foot of the header, at the start side. Use the inner-space sliders on the sizes tab to move them in from the edges.',
            ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom']],
        'cat_header_generic' => ['text', 'Line when a category has no description', 'Find your favorite products in our wide range {category} category.',
            'Shown under the title of a category that has no description of its own. {category} becomes the category\'s name. Empty it to show nothing.'],

        'cat_header_title_phone' => ['range', 'Title size · phone', 26,
            'A category can set its own in Catalog → Categories.',
            ['min' => 16, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'cat_header_title_desktop' => ['range', 'Title size · laptop', 40,
            'From 900px wide.',
            ['min' => 18, 'max' => 80, 'step' => 1, 'unit' => 'px']],
        'cat_header_weight' => ['select', 'Title weight', '700',
            'How bold the title is.',
            ['500' => 'Medium', '600' => 'Semi-bold', '700' => 'Bold', '800' => 'Extra bold']],
        'cat_header_desc_phone' => ['range', 'Description size · phone', 13,
            '',
            ['min' => 11, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'cat_header_desc_desktop' => ['range', 'Description size · laptop', 15,
            '',
            ['min' => 11, 'max' => 22, 'step' => 1, 'unit' => 'px']],
        'cat_header_lines' => ['range', 'Description lines', 2,
            'A longer description stops after this many lines (2, as asked). Turn on "Read more" below to let shoppers open the rest.',
            ['min' => 1, 'max' => 10, 'step' => 1, 'unit' => ' lines']],
        'cat_header_more' => ['bool', '"Read more" under a long description', false,
            'Off, as asked: the description shows its first lines and stops. On: a Read more link opens the rest.'],
        'cat_header_maxw' => ['range', 'Widest the text may run', 760,
            'The title and description wrap at this width, so a long description stays a comfortable read on a wide screen.',
            ['min' => 320, 'max' => 1400, 'step' => 20, 'unit' => 'px']],
        'cat_header_h_phone' => ['range', 'Height · phone', 190,
            'The least the header is on a phone. It grows if the title and description need more room.',
            ['min' => 80, 'max' => 480, 'step' => 10, 'unit' => 'px']],
        'cat_header_h_desktop' => ['range', 'Height · laptop', 300,
            'The least the header is on a screen 900px and wider.',
            ['min' => 100, 'max' => 640, 'step' => 10, 'unit' => 'px']],
        'cat_header_pad_y_phone' => ['range', 'Inner space top and bottom · phone', 28,
            '',
            ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'cat_header_pad_y_desktop' => ['range', 'Inner space top and bottom · laptop', 36,
            '',
            ['min' => 0, 'max' => 120, 'step' => 2, 'unit' => 'px']],
        'cat_header_pad_x_phone' => ['range', 'Inner space at the sides · phone', 20,
            '',
            ['min' => 0, 'max' => 60, 'step' => 2, 'unit' => 'px']],
        'cat_header_pad_x_desktop' => ['range', 'Inner space at the sides · laptop', 48,
            '',
            ['min' => 0, 'max' => 160, 'step' => 2, 'unit' => 'px']],
        'cat_header_radius' => ['range', 'Corner rounding', 18,
            '0 is square corners.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'cat_header_mt_phone' => ['range', 'Space above · phone', 6,
            'Between the breadcrumb and the header.',
            ['min' => 0, 'max' => 60, 'step' => 2, 'unit' => 'px']],
        'cat_header_mt_desktop' => ['range', 'Space above · laptop', 6,
            '',
            ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
        'cat_header_mb_phone' => ['range', 'Space below · phone', 22,
            'Between the header and the products.',
            ['min' => 0, 'max' => 60, 'step' => 2, 'unit' => 'px']],
        'cat_header_mb_desktop' => ['range', 'Space below · laptop', 26,
            '',
            ['min' => 0, 'max' => 80, 'step' => 2, 'unit' => 'px']],
    ];

    /** The bounds a typed batch size is held to, server-side. */
    public const BATCH_MIN = 4;

    public const BATCH_MAX = 96;

    /**
     * "Load all" has a ceiling, chosen here and said on the screen.
     *
     * A catalogue of 2,000 products as one page is a 2,000-card document — a
     * multi-megabyte response that ties a phone up for seconds and that a
     * crawler fetches in full. 200 is every category this shop has (the
     * owner's largest, "Skincare sets", is 45) with room to spare, and a
     * listing past it simply keeps the arrows for what is left.
     */
    public const LOAD_ALL_CAP = 200;

    /** The keys that are not CSS: skipped by isDefault(), never in css(). */
    private const LOAD_KEYS = ['load_mode', 'load_batch', 'load_batch_custom'];

    /** The category title header's keys (Lane PT): not CSS either, for the same reason. */
    public const HEADER_KEYS = [
        // What it is, where it shows, and how it looks (Lane PY's first tab).
        'cat_header', 'cat_header_box', 'cat_header_fallback', 'cat_header_brands', 'cat_header_box_brands',
        'cat_header_align', 'cat_header_treatment', 'cat_header_box_treatment', 'cat_header_overlay',
        'cat_header_box_style', 'cat_header_box_bg', 'cat_header_box_icon', 'cat_header_text',
        'cat_header_valign', 'cat_header_generic',
        // Sizes and spacing (the second).
        'cat_header_title_phone', 'cat_header_title_desktop', 'cat_header_weight',
        'cat_header_desc_phone', 'cat_header_desc_desktop', 'cat_header_lines', 'cat_header_more', 'cat_header_maxw',
        'cat_header_h_phone', 'cat_header_h_desktop',
        'cat_header_pad_y_phone', 'cat_header_pad_y_desktop', 'cat_header_pad_x_phone', 'cat_header_pad_x_desktop',
        'cat_header_radius', 'cat_header_mt_phone', 'cat_header_mt_desktop', 'cat_header_mb_phone', 'cat_header_mb_desktop',
    ];

    /** The look tab of the category header. (Lane PY) */
    public const HEADER_LOOK_KEYS = [
        'cat_header', 'cat_header_box', 'cat_header_fallback', 'cat_header_brands', 'cat_header_box_brands',
        'cat_header_align', 'cat_header_treatment', 'cat_header_box_treatment', 'cat_header_overlay',
        'cat_header_box_style', 'cat_header_box_bg', 'cat_header_box_icon', 'cat_header_text',
        'cat_header_valign', 'cat_header_generic',
    ];

    /** The sizes-and-spacing tab of the category header. (Lane PY) */
    public const HEADER_SIZE_KEYS = [
        'cat_header_title_phone', 'cat_header_title_desktop', 'cat_header_weight',
        'cat_header_desc_phone', 'cat_header_desc_desktop', 'cat_header_lines', 'cat_header_more', 'cat_header_maxw',
        'cat_header_h_phone', 'cat_header_h_desktop',
        'cat_header_pad_y_phone', 'cat_header_pad_y_desktop', 'cat_header_pad_x_phone', 'cat_header_pad_x_desktop',
        'cat_header_radius', 'cat_header_mt_phone', 'cat_header_mt_desktop', 'cat_header_mb_phone', 'cat_header_mb_desktop',
    ];

    /**
     * "Some type of shadow behind the name, so it will not merged with the
     * background. please give me multiple options to chooose from." (Lane PY)
     *
     * Numbered, because the owner answers an option sheet with a letter and a
     * number ("B + 3") and the labels here are the sheet's. The KEYS are what
     * App\Support\TitleHeader turns into a class, after checking them against
     * this list -- a select stores one of its own options or nothing.
     */
    public const TREATMENTS = [
        'shadow' => '1 · Soft shadow behind the words',
        'fade' => '2 · Dark fade on the text side',
        'frost' => '3 · Frosted panel behind the words',
        'label' => '4 · Solid label behind the title',
        'none' => '5 · None',
    ];

    /** The light box's looks, lettered for the same option sheet. (Lane PY) */
    public const BOX_STYLES = [
        'blush' => 'A · Blush icons (soft pink)',
        'cream' => 'B · Cream icons (warm cream, gold)',
        'mint' => 'C · Mint icons (pale green)',
        'lilac' => 'D · Lilac icons (pale lilac)',
        'plain' => 'E · Plain soft colour (no icons)',
        'custom' => 'F · My own colours (with icons)',
    ];

    public const TABS = [
        'width' => ['Page width',
            'One number for the whole shop. The cart page, the checkout and the slim footer keep their own width sliders on their own screens — they are pages asking for money, and a narrow ledger there is deliberate.',
            ['max', 'gutter', 'gutter_wide', 'header_follows']],
        'grid' => ['Product grid',
            'The column count is not set here — it is worked out from the smallest card and the width each grid actually has, so a grid beside the shop filters gets the right answer rather than the window\'s answer.',
            ['tile', 'tile_shop', 'cols_floor', 'cols_cap', 'gap', 'pin']],
        'loading' => ['Loading more products',
            'How /shop, every category, every brand page and the curated listings bring in more products: more on scroll, numbered arrows, or everything at once. Shoppers without JavaScript always get the arrows.',
            ['load_mode', 'load_batch', 'load_batch_custom']],
        'catheader' => ['Category header',
            'The top of every category page: its title and description over the category\'s picture, or in a light box when it has none. Each category can change its own picture, title, description, sizes and look in Catalog → Categories → Edit → Category header; anything left blank there follows this screen.',
            self::HEADER_LOOK_KEYS],
        'catheadersize' => ['Category header · sizes & spacing',
            'Title and description sizes, the header\'s height, the space inside and around it, and its corners -- for phones and for laptops (900px and wider) separately.',
            self::HEADER_SIZE_KEYS],
    ];

    /** Every key lives in `settings`, written by this module's own endpoint. */
    private const STORE = ModuleSchema::STORE_SETTING;

    /**
     * The stored keys are prefixed, and every one of them is new.
     *
     * No `alias` row is needed anywhere in this schema, which is unusual here
     * and worth saying: nothing on this screen is a pre-existing key something
     * else already reads. `--hd-max` IS pre-existing and IS read elsewhere,
     * which is exactly why `header_follows` is a switch that defers to
     * HeaderSettings rather than a second width box beside it — two controls
     * writing one value is the shape this repo keeps paying for.
     *
     * ▲ AND IT DEFERRED BY WRITING THE SAME PROPERTY FROM HERE, WHICH IS NOT
     * DEFERRING. HeaderSettings writes `--hd-max` into the `style` attribute of
     * `<header>` on every request; this class wrote it into `:root`, which an
     * inline declaration beats outright, so the switch moved nothing at any
     * width. It is read in HeaderSettings::maxWidthCss() now, where the property
     * is written. See cssVariables() below.
     */
    private const PREFIX = 'layout_';

    /**
     * This screen's point on ModuleSchema's seven policy axes.
     *
     * `invalid => reject` and `clamp => true` together are the whole security
     * story for a screen made of numbers: a slider cannot emit a value outside
     * its own range, so a POST that does is either a mistake or an attack and
     * neither deserves a stored value. A range is pulled to its bound; a select
     * that is not one of its own options is refused and reported, not silently
     * substituted, because "Site width: 1680" reading back after a failed save
     * is a screen lying about the shop.
     *
     * `blank => default` because every field here is a number or a switch:
     * there is no wording on this screen that an empty box could mean to
     * clear, so an emptied box can only be a mistake, and the shipped value is
     * the only answer that leaves the page renderable. `hex` and `markup` are
     * unreachable — no colour and no text field — and are named anyway so that
     * a field added later inherits a stated policy rather than a defaulted one.
     */
    public const POLICY = [
        'max' => 60,
        'blank' => 'default',
        'invalid' => 'reject',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
        'markup' => 'strip',
    ];

    /**
     * The custom property each numeric key is emitted as, in px.
     *
     * NOT a free-form map from setting to declaration. Every value that reaches
     * the page goes through cast() first and lands in a property whose name is
     * a constant in this file, so the only thing a POST can influence is the
     * NUMBER — never the property, never the unit, never the surrounding
     * syntax. That is rule 5 applied to a stylesheet: the thing printed
     * unescaped is a constant, and the setting is a clamped integer inside it.
     *
     * @var array<string, string>
     */
    private const PX_VARS = [
        'max' => '--site-max',
        'gutter' => '--site-gutter-min',
        'gutter_wide' => '--site-gutter-max',
        'tile' => '--kbb-tile',
        'tile_shop' => '--kbb-tile-shop',
        'gap' => '--kbb-gap',
    ];

    /** @var array<string, string> */
    private const UNITLESS_VARS = [
        'cols_floor' => '--kbb-cols-floor',
        'cols_cap' => '--kbb-cols-cap',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, array<string, mixed>> */
    public static function normalised(): array
    {
        /*
         * Memoised through ModuleSchema, not re-normalised per call. all() is
         * reached on every storefront request through the layout, and
         * normalise() walks nine fields validating seven policy axes on each.
         * `normalised()` is the cache ModuleSchema already keeps for exactly
         * this, and Tests\Support\StaticMemos resets it between tests.
         */
        return ModuleSchema::normalised('site_layout', self::SCHEMA, self::POLICY, self::overrides());
    }

    /**
     * Where each field's value lives, handed to normalise() as an override.
     *
     * Declared here rather than repeated nine times in SCHEMA. The prefix is
     * applied as the `alias` at the same time, so read() and write() go to
     * `layout_max` while the screen, the tests and this file all say `max`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        $out = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = ['store' => self::STORE, 'alias' => self::PREFIX.$key];
        }

        /*
         * A TYPED NUMBER IS CLAMPED; A TYPED WORD IS REFUSED. (Lane PI-B)
         *
         * This screen's `clamp` policy would turn "abc" into (int) 0 and then
         * into the minimum, so a typo would save as 4 and read back "Saved".
         * The batch box is the one field here a person types into, so it gets
         * its own rule: digits (and surrounding spaces) or nothing, then held
         * to BATCH_MIN..BATCH_MAX. null is `invalid => reject`, which the
         * endpoint reports as a 422 naming the field.
         */
        $out['load_batch_custom']['rule'] = static function (mixed $raw): ?int {
            if (is_int($raw)) {
                $n = $raw;
            } elseif (is_string($raw) && preg_match('/^\s*(\d{1,6})\s*$/', $raw, $m) === 1) {
                $n = (int) $m[1];
            } else {
                return null;
            }

            return max(self::BATCH_MIN, min(self::BATCH_MAX, $n));
        };

        /*
         * THE LIGHT BOX'S TWO COLOURS (Lane PY): `expand`, not this screen's
         * `strict`. The owner may type #fef as readily as #ffeeff; `expand`
         * requires the `#`, accepts three or six hex digits, stores six in
         * upper case and refuses everything else -- so what reaches the
         * header's `style` attribute is always exactly `#RRGGBB`.
         */
        $out['cat_header_box_bg']['hex'] = 'expand';
        $out['cat_header_box_icon']['hex'] = 'expand';

        return $out;
    }

    /**
     * Every value, cast, with the shipped default where nothing is stored.
     *
     * NOT ModuleSchema::read(). That method takes no `$policy` and no
     * `$overrides` — it calls `normalise($schema)` bare — so it would look up
     * `max` in the settings table instead of `layout_max` and would cast every
     * field under DEFAULT_POLICY rather than this screen's. It read back nine
     * shipped defaults on a shop that had saved nine values, which is the
     * quietest possible way for a settings screen to be wrong. The one-line
     * loop below is the same work with this screen's policy and aliases
     * applied, and SiteLayoutSettingsTest saves a value and reads it back for
     * every field, which is the assertion that would have caught it.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::normalised() as $key => $field) {
            $saved = $this->settings->get($field['alias'], null);

            $out[$key] = $saved === null
                ? $field['default']
                : (ModuleSchema::cast($field, $saved) ?? $field['default']);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function cast(string $key, mixed $value): mixed
    {
        $fields = self::normalised();

        if (! isset($fields[$key])) {
            return null;
        }

        return ModuleSchema::cast($fields[$key], $value);
    }

    /**
     * Save, returning the keys it refused so the caller can report them.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $written = [];
        $rejected = [];

        foreach (self::normalised() as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $cast = ModuleSchema::cast($field, $values[$key]);

            if ($cast === null) {
                $rejected[$key] = $field['label'];

                continue;
            }

            $this->settings->set($field['alias'], is_bool($cast) ? ($cast ? '1' : '0') : (string) $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /** True when every field is still at its shipped default. */
    public function isDefault(): bool
    {
        $values = $this->all();

        foreach (self::normalised() as $key => $field) {
            if (in_array($key, self::LOAD_KEYS, true) || in_array($key, self::HEADER_KEYS, true)) {
                continue;
            }

            if ($values[$key] !== $field['default']) {
                return false;
            }
        }

        return true;
    }

    /**
     * How this shop loads more products on a listing: arrows, scroll or all.
     *
     * Always one of the select's own options — cast() refuses anything else
     * on the way in and all() falls back to the default on the way out.
     */
    public function loadMode(): string
    {
        return (string) $this->get('load_mode');
    }

    /** The batch size "Load more on scroll" uses, 4–96. */
    public function batchSize(): int
    {
        $c = $this->all();

        return $c['load_batch'] === 'custom'
            ? max(self::BATCH_MIN, min(self::BATCH_MAX, (int) $c['load_batch_custom']))
            : (int) $c['load_batch'];
    }

    /**
     * Products per page on a listing, given what the arrows have always used.
     *
     * Arrows: the listing's own number, unchanged (the shop's
     * `products_per_page`, a curated listing's 24). Scroll: one batch per
     * page, so ?paged=N is batch N and the no-JavaScript arrows walk the same
     * slices the scroll loads. All: LOAD_ALL_CAP.
     */
    public function perPage(int $arrows): int
    {
        return match ($this->loadMode()) {
            'scroll' => $this->batchSize(),
            'all' => self::LOAD_ALL_CAP,
            default => max(1, $arrows),
        };
    }

    /**
     * The declarations this shop needs, or an EMPTY STRING when it needs none.
     *
     * ── WHY EMPTY RATHER THAN A BLOCK OF DEFAULTS ───────────────────────────
     *
     * Rule 1: a new setting ships at the value the page already has, so
     * applying the package moves nothing. A style block that restated the
     * defaults would satisfy that in pixels and break it in bytes — it would
     * add a `<style>` element to EVERY storefront page, which is exactly what
     * StorefrontEnglishUnchangedTest is pinning, on forty pages at once, for a
     * change that renders identically. So the page carries nothing until a
     * slider moves, the way `<style id="kbb-brand-accent">` already does two
     * lines above it in layouts/store.blade.php, and for the same reason.
     *
     * It also means the defaults have exactly one home — the `:root` block in
     * kbb.css — instead of a copy in PHP that can drift from it.
     * SiteLayoutDefaultsMatchCssTest is what stops them drifting.
     */
    public function cssVariables(): string
    {
        if ($this->isDefault()) {
            return '';
        }

        $c = $this->all();
        $out = [];

        foreach (self::PX_VARS as $key => $var) {
            $out[] = $var.':'.(int) $c[$key].'px';
        }

        foreach (self::UNITLESS_VARS as $key => $var) {
            $out[] = $var.':'.(int) $c[$key];
        }

        /*
         * ── `header_follows` IS NOT EMITTED HERE, AND IT USED TO BE ──────────
         *
         * It wrote `--hd-max:var(--site-max)` into this `:root` block, and that
         * declaration could never reach the element it was aimed at.
         * HeaderSettings::cssVariables() writes the same property into the
         * `style` attribute of `<header>` itself, on every request, whether or
         * not anything has been saved — and an inline declaration on the element
         * beats a `:root` one outright. `header .wrap`, the only reader of
         * `--hd-max` anywhere, is a CHILD of `<header>`, so it inherited the
         * inline value and never saw this one. Measured with the switch saved on:
         * the header stayed 1280px at 1280, 1680 and 1920 while the page
         * container went to 1680.
         *
         * So the switch is read where the property is actually written, by
         * HeaderSettings::maxWidthCss(), which has the whole story. One property,
         * one writer, at the level that wins.
         *
         * The `$c` above is still read for every other field; this key is
         * deliberately absent rather than forgotten.
         */

        return implode(';', $out);
    }

    /**
     * The whole stylesheet this shop needs, or '' when it needs none.
     *
     * ── WHY THE PIN CANNOT BE A CUSTOM PROPERTY ─────────────────────────────
     *
     * Everything above is a value and rides in `:root`. The pinned column count
     * is not a value, it is a DECLARATION THAT MUST NOT APPLY ON A PHONE, and a
     * custom property cannot be reverted to its inherited value at a
     * breakpoint: `--kbb-track:initial` is the guaranteed-invalid value, which
     * turns `repeat(auto-fill, var(--kbb-track))` into an invalid declaration
     * and the grid into a single full-width column. The note in kbb.css records
     * that, because it was the first draft of this method.
     *
     * So a pin is a real rule in a real media query, and the phone is excluded
     * by the query rather than by undoing anything. Which is also why this
     * method exists at all rather than everything going in a `style` attribute:
     * a `style` attribute cannot hold a media query.
     *
     * ── RULE 5, ON A STYLESHEET ─────────────────────────────────────────────
     *
     * Every byte of the selector, the property, the unit and the punctuation
     * below is a literal in this file. The only thing a POST can influence is
     * the integer, and it reaches here having been cast against a `select`
     * whose options are the strings 'auto' and '2'..'8' — so `(int)` on it is
     * 2..8 or nothing at all, and `pin` is checked against 'auto' before any of
     * it runs. No setting is interpolated into a property name, a selector or a
     * unit anywhere in this class.
     *
     * 901px, not 900px, deliberately: kbb-shop.css drops ITS pin below 900 and
     * these two must not both apply and both not apply at 900.0 exactly.
     */
    public function css(): string
    {
        $vars = $this->cssVariables();

        if ($vars === '') {
            return '';
        }

        $css = ':root{'.$vars.'}';

        $pin = (int) $this->all()['pin'];

        if ($pin >= 2) {
            $css .= '@media(min-width:901px){.kbb-pgrid,.rel{grid-template-columns:repeat('
                 .$pin.',minmax(0,1fr))}}';
        }

        return $css;
    }
}
