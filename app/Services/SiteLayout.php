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
         * The "44 products" beside the Filters button on /shop, every category
         * and every brand page. The owner, 2 October: "turn hide by default the
         * products count beside the filter button". Hidden, as asked; this
         * brings it back. (Integrator, 2.60.358)
         */
        'show_count' => ['bool', 'Show the product count beside Filters', false,
            'Off: the "44 products" line beside the Filters button is not shown on the shop, category and brand pages. On: it is.'],

        /*
         * ── NO FILTERS, AND NO WAY OFF THE PAGE ───────────────────── Lane SO ──
         *
         * The owner, 5 October: "i want the if user come to any category or
         * brand, the app should not display any external link or shop filter
         * etc. so that user can stick to that page which she actually visited",
         * and of the filters: "remove the filter at all. i mean keep turned off
         * completely on all pages by default." Both ship OFF, as he asked; each
         * switch puts the old page back. Off means NOT RENDERED -- no filter
         * column, no Show filters button, no /shop/?brand= link in the HTML --
         * rather than hidden by CSS. Not CSS, so css() never sees them.
         */
        'filters_d' => ['bool', 'Filters · laptop', false,
            'Off, as you asked: no filter column and no "Show filters" button on the shop and category pages -- just the products. On: the filter column comes back, closed until the shopper opens it, as before. Phones have their own switch below.'],
        'shop_links' => ['bool', 'Links to the whole shop on category and campaign pages', false,
            'Off, as you asked: a category page, /super-sale/ and the skin-concern pages show no "All products" link to the shop, so a shopper stays on the page she came to. The site menu and footer are not affected. On: the links come back.'],

        /*
         * ── SOLD-OUT PRODUCTS ON EVERY LISTING ───────────────────── Lane SX ──
         *
         * The owner: "i should have option on category / brands etc backend
         * setting page, where i can exclude the sold out products or show at
         * very end." An OPTION, so it ships at today's page: "show". Each
         * category and brand can choose for itself in Catalog → Categories /
         * Brands → Edit; this is what the rest follow. Not CSS -- a query --
         * so css() and isDefault() never see it. App\Support\SoldOut.
         */
        'sold_out' => ['select', 'Sold-out products', 'show',
            'How sold-out products sit in /shop, search, every category and brand page, the curated listings (Super Sale, New In, Best Sellers, under AED 54, skin concerns), product shortcodes and the homepage rows. Show as usual: where their order puts them. At the very end: after every in-stock product, each group in its usual order. Hide: left out of the grid and the product count; the product page itself still opens. A category or brand can choose differently in Catalog → Categories / Brands → Edit.',
            [
                'show' => 'Show as usual',
                'end' => 'Show at the very end',
                'hide' => 'Hide from listings',
            ]],

        /*
         * ── THE FILTERS DRAWER ON A PHONE ─────────────────────────── Lane FP ──
         *
         * The owner, on his phone's Filters drawer: "i want the filters panel
         * width control". The drawer exists below 900px only -- a laptop has the
         * rail beside the grid, which these do not touch -- so there is one pair,
         * not a phone and a laptop pair.
         *
         * Two numbers because the drawer is two numbers today: `width:300px;
         * max-width:90vw` in kbb-shop.css, i.e. min(90% of the screen, 300px).
         * Shipped at exactly those, so nothing moves until he drags one (rule 1).
         * Clamped integers in custom properties whose names are constants in
         * PX_VARS / UNITLESS_VARS below; the sheet keeps the same two numbers as
         * its fallbacks, which SiteLayoutDefaultsMatchCssTest pins.
         */
        'filter_w' => ['range', 'Filters drawer width · phone', 90,
            'How much of a phone screen the Filters drawer covers when a shopper opens it, up to the limit below. Today: 90%, held to 300px — 300px on most phones, which leaves the shop showing beside it to tap and close. 100% covers the whole screen.',
            ['min' => 60, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'filter_max' => ['range', 'Filters drawer · never wider than', 300,
            'The widest the drawer gets, on a big phone or a tablet. To widen it on an ordinary phone, raise this as well as the width above.',
            ['min' => 260, 'max' => 600, 'step' => 10, 'unit' => 'px']],

        /*
         * The owner, 5 October, of a phone category page: "turn off the filters
         * for now. and make number of columns to select 1 or 2, max. and reduce
         * the capsule size of sort." Both as he asked. (2.60.393)
         */
        'filters_m' => ['bool', 'Filters button · phone', false,
            'Off, as you asked: phones show no Filters button above the products, and no filter drawer is sent to them. Laptops follow "Filters · laptop" above.'],
        'cols_m' => ['bool', 'Column buttons (1 or 2) · phone', true,
            'On, as you asked: phones get two small buttons beside Sort to show one product per row or two.'],

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
        // 2.60.405. The owner: "i don't need that the url changed from pages
        // 1-2-3 etc. ... the url must not change, only the more products
        // loads". Off: the address stays exactly as opened while batches load.
        'load_url' => ['bool', 'Show the page number in the address', false,
            'Only used by "Load more on scroll". Off: the address stays as the shopper opened it while more products load, and Back from a product returns to the same place with the same products showing. On: the address follows each batch (…?page=2, ?page=3).'],

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
        'cat_header_align' => ['select', 'Text alignment · phone', 'start',
            'Start is the left edge on the English shop and the right edge on the Arabic one -- where the title sat before. A category can choose its own in Catalog → Categories.',
            ['start' => 'Start (left in English, right in Arabic)', 'center' => 'Centred', 'end' => 'End (right in English, left in Arabic)']],
        'cat_header_treatment' => ['select', 'Keep the words readable · on a picture · phone', 'shadow',
            'What sits behind the title and description over a picture, so they never merge into it. Works together with "Darken the picture".',
            self::TREATMENTS],
        'cat_header_box_treatment' => ['select', 'Keep the words readable · on the light box · phone', 'none',
            'The same choice on the light box. A pale box needs nothing, so it ships at None.',
            self::TREATMENTS],
        'cat_header_overlay' => ['range', 'Darken the picture', 40,
            'How much the picture is darkened (or, with dark text, lightened) so the words stay readable.',
            ['min' => 0, 'max' => 85, 'step' => 5, 'unit' => '%']],
        'cat_header_box_style' => ['select', 'Light box style · phone', 'blush',
            'The look of the box a category with no picture gets. A, B, C and D carry a soft pattern of beauty-product line icons; E is the plain colour below; F uses both colours below.',
            self::BOX_STYLES],
        'cat_header_box_bg' => ['colour', 'Box colour · E and F', '#FFF4EE',
            'Used by "Plain soft colour" and "My own colours". A hex colour such as #FFF4EE or #FEF.'],
        'cat_header_box_icon' => ['colour', 'Icon colour · F', '#EFA889',
            'The colour of the icons for "My own colours". Keep it close to the box colour so the icons stay a pattern, not a picture.'],
        'cat_header_text' => ['select', 'Text colour · phone', 'auto',
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
        'cat_header_valign' => ['select', 'Where the words sit, top to bottom · phone', 'bottom',
            'Bottom, as asked: the title and description sit at the foot of the header, at the start side. Use the inner-space sliders on the sizes tab to move them in from the edges.',
            ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom']],
        'cat_header_generic' => ['text', 'Line when a category has no description', 'Find your favorite products in our wide range {category} category.',
            'Shown under the title of a category that has no description of its own. {category} becomes the category\'s name. Empty it to show nothing.'],

        /*
         * ── PHONE AND LAPTOP, SEPARATELY ─────────────────────── (Lane QC) ──
         *
         * The owner: "i need the same designs on backend to choose the
         * category banner designs, text style etc and for mobile also."
         *
         * The five choices above -- and 2.60.350's "Where the words sit" --
         * are now the PHONE's (under 900px, the header's own breakpoint in
         * kbb-title-header.css) and these six are the LAPTOP's (900px and
         * wider). Defaults are the shipped ones on both.
         *
         * NOTHING A SHOP SAVED MAY JUMP. A shop that saved "Centred" before
         * this has `layout_cat_header_align = center` and no laptop row at
         * all, and all() below hands the laptop key the PHONE's resolved value
         * whenever the laptop row is absent -- so the one value he chose is
         * both devices' value until he gives the laptop one of its own. The
         * read path, not a data migration: it cannot be skipped by a package
         * whose migrations did not run, and it cannot race PY's reset.
         * DEVICE_PAIRS is that map.
         */
        'cat_header_align_desktop' => ['select', 'Text alignment · laptop', 'start',
            'From 900px wide. Start is the left edge on the English shop and the right edge on the Arabic one.',
            ['start' => 'Start (left in English, right in Arabic)', 'center' => 'Centred', 'end' => 'End (right in English, left in Arabic)']],
        'cat_header_treatment_desktop' => ['select', 'Keep the words readable · on a picture · laptop', 'shadow',
            'From 900px wide.',
            self::TREATMENTS],
        'cat_header_box_treatment_desktop' => ['select', 'Keep the words readable · on the light box · laptop', 'none',
            'From 900px wide.',
            self::TREATMENTS],
        'cat_header_box_style_desktop' => ['select', 'Light box style · laptop', 'blush',
            'From 900px wide.',
            self::BOX_STYLES],
        'cat_header_text_desktop' => ['select', 'Text colour · laptop', 'auto',
            'From 900px wide.',
            ['auto' => 'Automatic', 'light' => 'White', 'dark' => 'Dark']],
        'cat_header_valign_desktop' => ['select', 'Where the words sit, top to bottom · laptop', 'bottom',
            'From 900px wide.',
            ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom']],

        /*
         * ── "MAKE EDITS AS PER NEED" ─────────────────────────── (Lane QC) ──
         *
         * The owner, pointing at the option sheet: "make sure that i should
         * have these designs to chooose from and make edits as per need."
         *
         * So each design can be fine-tuned after it is chosen, and EVERY
         * DEFAULT BELOW IS THE DESIGN AS THE SHEET DRAWS IT: the four icon
         * boxes' colours are the presets kbb-title-header.css carries, every
         * percentage is 100 (the stylesheet's own number, unscaled), the
         * frosted panel's blur is its 12px. TitleHeader writes a tweak onto
         * the header only when it differs from that default, so a shop that
         * touches nothing sends the byte-identical header it sent before.
         *
         * Colours: `expand` (#rgb or #rrggbb, stored #RRGGBB). The three that
         * may be blank -- the label's two colours and the description colour
         * -- are `text` fields with a rule in overrides(), because ModuleSchema
         * will not hand a `colour` to a rule: blank is "Automatic", #rgb or
         * #rrggbb is stored #RRGGBB, anything else is refused. TitleHeader
         * checks ^#[0-9A-F]{6}$ again before printing one.
         */
        'cat_header_blush_bg' => ['colour', 'A · Blush icons · box colour', '#FDF0F4', ''],
        'cat_header_blush_ic' => ['colour', 'A · Blush icons · icon colour', '#E3A1B5', ''],
        'cat_header_cream_bg' => ['colour', 'B · Cream icons · box colour', '#FBF4EA', ''],
        'cat_header_cream_ic' => ['colour', 'B · Cream icons · icon colour', '#CDA57B', ''],
        'cat_header_mint_bg' => ['colour', 'C · Mint icons · box colour', '#EEF8F2', ''],
        'cat_header_mint_ic' => ['colour', 'C · Mint icons · icon colour', '#8FC9AB', ''],
        'cat_header_lilac_bg' => ['colour', 'D · Lilac icons · box colour', '#F4F0FB', ''],
        'cat_header_lilac_ic' => ['colour', 'D · Lilac icons · icon colour', '#B7A3DD', ''],
        'cat_header_icon_strength' => ['range', 'Icon strength', 60,
            'How strongly the icon pattern shows. 60% is the design as drawn.',
            ['min' => 10, 'max' => 100, 'step' => 5, 'unit' => '%']],
        'cat_header_icon_size' => ['range', 'Icon size', 100,
            'Bigger icons are fewer icons. 100% is the design as drawn.',
            ['min' => 50, 'max' => 200, 'step' => 10, 'unit' => '%']],
        'cat_header_shadow_strength' => ['range', '1 · Soft shadow · strength', 100,
            '100% is the shadow as drawn on the option sheet.',
            ['min' => 0, 'max' => 200, 'step' => 10, 'unit' => '%']],
        'cat_header_shadow_blur' => ['range', '1 · Soft shadow · blur', 100,
            'How far the shadow spreads. 100% is as drawn.',
            ['min' => 0, 'max' => 300, 'step' => 10, 'unit' => '%']],
        'cat_header_fade_dark' => ['range', '2 · Dark fade · darkness', 100,
            '100% is the fade as drawn.',
            ['min' => 0, 'max' => 150, 'step' => 5, 'unit' => '%']],
        'cat_header_fade_reach' => ['range', '2 · Dark fade · how far it reaches', 100,
            'How far across the header the fade runs. 100% is as drawn.',
            ['min' => 50, 'max' => 150, 'step' => 5, 'unit' => '%']],
        'cat_header_frost_opacity' => ['range', '3 · Frosted panel · how solid', 100,
            '100% is the panel as drawn.',
            ['min' => 20, 'max' => 200, 'step' => 10, 'unit' => '%']],
        'cat_header_frost_blur' => ['range', '3 · Frosted panel · blur', 12,
            'How much the picture behind the panel is blurred. 12px is as drawn.',
            ['min' => 0, 'max' => 30, 'step' => 1, 'unit' => 'px']],
        'cat_header_frost_radius' => ['range', '3 · Frosted panel · corner rounding', 14,
            'At 14 (as drawn) the panel follows the header’s own corner rounding, 4px less; any other number is used as it is.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'cat_header_label_bg' => ['text', '4 · Solid label · label colour', '',
            'Blank is the design as drawn: berry behind white words, white behind dark words.'],
        'cat_header_label_fg' => ['text', '4 · Solid label · title colour on the label', '',
            'Blank is the design as drawn.'],
        'cat_header_letter' => ['range', 'Title letter-spacing', -2,
            'In hundredths of the title size. -2 is the design as drawn. Arabic titles always keep 0: spacing pulls joined letters apart.',
            ['min' => -5, 'max' => 20, 'step' => 1, 'unit' => '/100 em']],
        'cat_header_desc_colour' => ['text', 'Description colour', '',
            'Blank is automatic: white over a picture, soft dark on the light box.'],

        /*
         * RANDOM LIGHT BOX (2.60.352). The owner: "can we have options to use
         * random layout on random categories, where we didin't upload the
         * background image yet. so on each page load, it will give random
         * colored background as we have multiple designs."
         *
         * The SCHEMA default is OFF so a fresh install (and every test) draws
         * the same box every time; 2027_07_16_000100 switches it ON for his
         * shop, because he asked for it. A category with its own box style
         * (Catalog → Categories → Edit) keeps it; a category with a banner is
         * never affected. One pick per page view, the same on phone and
         * laptop. Storefront HTML is `private, no-cache` (CacheHeaders), so no
         * cache freezes a pick.
         */
        'cat_header_box_random' => ['bool', 'A different light box on every visit', false,
            'On: a category with no banner gets one of the styles ticked below, picked again each time the page loads. A category given its own style in Catalog → Categories keeps it. Off: every such category uses the box style chosen above.'],
        'cat_header_rand_blush' => ['bool', 'In the mix · A Blush icons', true, ''],
        'cat_header_rand_cream' => ['bool', 'In the mix · B Cream icons', true, ''],
        'cat_header_rand_mint' => ['bool', 'In the mix · C Mint icons', true, ''],
        'cat_header_rand_lilac' => ['bool', 'In the mix · D Lilac icons', true, ''],
        'cat_header_rand_plain' => ['bool', 'In the mix · E Plain soft colour', false, ''],
        /*
         * The owner, 2 October: "the background banner image in mobile should
         * display full, not any cut from left or right". On a phone the header
         * takes the picture's own shape, so the whole picture shows; off gives
         * back the cropped frame and its Phone crop. ON, as he asked.
         * (Integrator, 2.60.358)
         */
        // (2.60.393) Off, the owner: "the background image on mobile should
        // cover the whole area by middle and center, doesn't matter what size
        // the image is" -- the picture fills the phone header, centred.
        'cat_header_phone_whole' => ['bool', 'Show the whole picture on phones', false,
            'On: on a phone the header takes the picture\'s own shape, so nothing is cut from its left or right; the words sit on it as set above. Off: the phone header keeps its own height and the picture is cropped to fill it (Catalog → Categories → Phone crop chooses which part).'],

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

        /*
         * THE BRAND PAGE. (2.60.376)
         *
         * The owner, 4 October: "for Brand Page, remove the Shop all button,
         * and keep Name, along with description ... remove also popular right
         * now, and view all. as the brand page visit will give full results
         * without any pagination etc." He asked, so all three ship at what he
         * asked for; each switch puts the old page back. Not CSS: isDefault()
         * and css() skip them (BRAND_KEYS).
         */
        'brand_all' => ['bool', 'Show every product of the brand on one page', true,
            'On: a brand page lists all of the brand\'s products at once, with no pages and no "load more" (up to '.self::BRAND_ALL_CAP.'; past that the arrows take over). Off: the brand page loads more the way "How more products load" says.'],
        'brand_cta' => ['bool', 'Show the "Shop all" button under the brand name', false,
            'Off, as you asked: the brand page shows the logo, the name and the description. On: the "Shop all <brand>" button comes back, linking to the shop filtered to this brand.'],
        'brand_popular' => ['bool', 'Show "Popular right now" and "View all" above the products', false,
            'Off, as you asked: the products start straight under the brand. On: the heading and the "View all" link come back.'],
        /*
         * THE BRAND HEADER'S SHAPE, AND THE RING ROUND THE LOGO.     (Lane BH)
         *
         * The owner: "i don't like the heighted banner for brand, the content
         * should be sqeezed. logo + name in one row, then description in
         * another, that's it. need same less heighted banner in mobile as like
         * on desktop ... it will be auto circled with outer brand color
         * border." He asked, so both ship ON; Classic and the switch put the
         * old page back. Not CSS: in BRAND_KEYS, so isDefault()/css() skip them.
         */
        /*
         * Lane BR2 adds PANEL and ships it, as the owner asked: "logo will be on
         * th background image, beside logo, brand name, and downside brand
         * description ... almost 60% of the page width, and in mobile the
         * description will come under banner". Compact and Classic stay.
         */
        'brand_hero' => ['select', 'Brand header style', 'panel',
            'Panel, as you asked: the banner is the background, and on it a panel about 60% wide holds the logo, the brand name beside it and the description under them. On a phone the logo and name sit in a capsule on the banner and the description comes below it. Compact: a small round logo and the brand name in one row, the description under them. Classic: the large logo beside the name, as before.',
            [
                'panel' => 'Panel -- logo, name and description on the banner',
                'compact' => 'Compact -- logo and name in one row',
                'classic' => 'Classic -- as before',
            ]],
        'brand_ring' => ['bool', 'Ring round the brand logo, in the brand\'s colour', true,
            'On, as you asked: the logo sits in a circle with a ring in the brand\'s own colour, taken from the logo itself (change it per brand in Catalog → Brands → Edit → Ring colour). A brand with no colour gets the shop pink. Off: the plain circle, as before.'],
        /*
         * The owner, 5 October: "in mobile brand page, also the background
         * image should cover the whole header area, instead of repeating
         * horizontal or vertical". Category header → "Show the whole picture on
         * phones" shrinks the banner to fit and fills the room around it with a
         * blurred copy of the same picture, which on a wide brand banner reads
         * as the image repeated. ON, as he asked: on a brand page the picture
         * covers the phone header edge to edge. Categories keep their switch.
         * (2.60.387)
         */
        'brand_phone_cover' => ['bool', 'Picture covers the header on phones', true,
            'On, as you asked: on a phone the brand banner fills the whole header, edge to edge (its sides are cropped to fit). Off: brand pages follow Category header → "Show the whole picture on phones".'],
        /*
         * THE PANEL HEADER'S LOOK AND SIZES.                        (Lane BR2)
         *
         * The shop's values; each brand can change every one of them in the
         * "Edit brand header" pop-up on its own page (stored in
         * `brands.header_layout`, App\Support\BrandPanel). Only Panel reads
         * them. Not CSS on :root: in BRAND_KEYS, so isDefault()/css() skip
         * them, and BrandPanel prints each as a clamped integer or an option key.
         */
        'brand_panel_style' => ['select', 'Panel · background', 'frost',
            'Only for the Panel header. Frosted white: a soft white panel with dark text, readable on any banner. Brand colour: a dark shade of the brand\'s own colour with white text.',
            [
                'frost' => 'Frosted white',
                'brand' => 'Brand colour',
            ]],
        'brand_pill' => ['select', 'Panel · logo and name on phones', 'capsule',
            'Only for the Panel header: the shape behind the logo and name on a phone\'s banner.',
            [
                'capsule' => 'Capsule',
                'rect' => 'Rectangle',
            ]],
        'brand_logo_shape' => ['select', 'Panel · logo shape', 'circle',
            'Only for the Panel header. Circle for round logos, Rectangle for wide wordmarks. Each brand can pick its own.',
            [
                'circle' => 'Circle',
                'rect' => 'Rectangle',
            ]],
        'brand_header_w' => ['range', 'Panel · header width', 100,
            'Only for the Panel header, on laptops: how much of the page width the banner takes, centred.',
            ['min' => 60, 'max' => 100, 'step' => 1, 'unit' => '%']],
        'brand_banner_h' => ['range', 'Panel · laptop · header height', 270,
            'Only for the Panel header: the height of the WHOLE header on a laptop -- the banner, with the panel on it. It grows past this only if the panel needs more room.',
            ['min' => 160, 'max' => 460, 'step' => 5, 'unit' => 'px']],
        'brand_banner_h_m' => ['range', 'Panel · phone · banner height', 165,
            'Only for the Panel header: the banner on a phone, with the logo and name on it. The description card comes BELOW it, so the whole phone header is this, plus the gap, plus the card.',
            ['min' => 100, 'max' => 300, 'step' => 5, 'unit' => 'px']],
        'brand_content_w' => ['range', 'Panel · content width', 60,
            'Only for the Panel header, on laptops: the panel\'s width, as a share of the banner\'s.',
            ['min' => 40, 'max' => 85, 'step' => 1, 'unit' => '%']],
        'brand_img_pos' => ['select', 'Panel · picture position', 'center',
            'Only for the Panel header: which part of the banner stays in view when it is cropped to fit.',
            [
                'left' => 'Left',
                'center' => 'Centre',
                'right' => 'Right',
            ]],

        /*
         * THE PANEL HEADER'S BOX, POSITION AND TYPE.               (Lane BR3)
         *
         * The owner: "for brand i need this as you proposed. with logo circle
         * or rectangle to choose from, and all controls options like box
         * spacings, positioning etc and to adjust the height of overal header
         * also, and font sizes etc. ... allow controls for desktop and mobile
         * both on the front-end."
         *
         * Each brand changes these in its own "Edit brand header" pop-up; these
         * are the shop's values. Every default is design A's own number (the
         * Frosted glass panel he picked), and BrandPanel::QUIET holds the same
         * numbers: a size at its default prints nothing, so an untouched brand
         * page keeps its markup.
         */
        'brand_panel_x' => ['select', 'Panel · laptop · panel across the banner', 'left',
            'Only for the Panel header, on laptops: the panel on the left of the banner (as designed), in the middle, or on the right.',
            [
                'left' => 'Left',
                'center' => 'Centre',
                'right' => 'Right',
            ]],
        'brand_panel_y' => ['select', 'Panel · laptop · panel up and down', 'middle',
            'Only for the Panel header, on laptops: the panel in the middle of the banner\'s height, at its top, or at its bottom.',
            [
                'middle' => 'Middle',
                'top' => 'Top',
                'bottom' => 'Bottom',
            ]],
        'brand_panel_pad' => ['range', 'Panel · laptop · space inside the panel', 26,
            'Only for the Panel header: the padding at the panel\'s sides; top and bottom are 4px less.',
            ['min' => 8, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'brand_panel_inset' => ['range', 'Panel · laptop · panel distance from the banner edge', 36,
            'Only for the Panel header: from the banner\'s side; from its top and bottom it is two thirds of this. At 36 it narrows on a small laptop.',
            ['min' => 0, 'max' => 120, 'step' => 1, 'unit' => 'px']],
        'brand_desc_gap' => ['range', 'Panel · laptop · gap between the name and the description', 12,
            'Only for the Panel header: the space between the logo-and-name row and the description, inside the panel.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'brand_name_fs' => ['range', 'Panel · laptop · brand name size', 34,
            'Only for the Panel header. At 34 it narrows on a small laptop; any other size is kept exactly.',
            ['min' => 18, 'max' => 56, 'step' => 1, 'unit' => 'px']],
        'brand_desc_fs' => ['range', 'Panel · laptop · description size', 15,
            'Only for the Panel header: the description\'s text, inside the panel.',
            ['min' => 12, 'max' => 22, 'step' => 1, 'unit' => 'px']],
        'brand_logo_size' => ['range', 'Panel · laptop · logo size', 72,
            'Only for the Panel header: the logo\'s height (a circle is as wide; a rectangle is 2.3 times as wide).',
            ['min' => 40, 'max' => 120, 'step' => 1, 'unit' => 'px']],
        /*
         * Lane BR4 moves this default from Bottom left to Bottom centre: the
         * owner, "give control make name positioning ... and centered align".
         * On a phone the name's position IS this capsule's place on the banner.
         */
        'brand_pill_at' => ['select', 'Panel · phone · name position (with the logo, when it shows)', 'bottom-center',
            'Only for the Panel header, on phones: where the capsule with the brand name (and the logo, when it shows) sits on the banner. Bottom centre, as you asked.',
            [
                'bottom-center' => 'Bottom centre',
                'bottom-left' => 'Bottom left',
                'bottom-right' => 'Bottom right',
                'top-left' => 'Top left',
                'top-center' => 'Top centre',
                'top-right' => 'Top right',
            ]],
        'brand_pill_inset_m' => ['range', 'Panel · phone · capsule distance from the banner edge', 12,
            'Only for the Panel header, on phones.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'brand_card_gap_m' => ['range', 'Panel · phone · gap between the banner and the description', 12,
            'Only for the Panel header, on phones: the space above the description card.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'brand_card_pad_m' => ['range', 'Panel · phone · space inside the description card', 14,
            'Only for the Panel header, on phones: top and bottom; the sides are 2px more.',
            ['min' => 6, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'brand_name_fs_m' => ['range', 'Panel · phone · brand name size', 22,
            'Only for the Panel header, on phones: the name in the capsule.',
            ['min' => 14, 'max' => 36, 'step' => 1, 'unit' => 'px']],
        'brand_desc_fs_m' => ['range', 'Panel · phone · description size', 14,
            'Only for the Panel header, on phones: the text in the card below the banner.',
            ['min' => 12, 'max' => 20, 'step' => 1, 'unit' => 'px']],
        'brand_logo_size_m' => ['range', 'Panel · phone · logo size', 52,
            'Only for the Panel header, on phones: the logo\'s height in the capsule.',
            ['min' => 28, 'max' => 80, 'step' => 1, 'unit' => 'px']],

        /*
         * THE LOGO, THE ALIGNMENT AND "READ MORE".                 (Lane BR4)
         *
         * The owner, 5 October: "i want to turn off the logo by default, and
         * give control make name positioning, and with description i want read
         * more / read less after two lines, and centered align." He asked, so
         * each ships at what he asked for -- logo off, name and description
         * centred, the description cut at two lines with Read more -- and each
         * brand can change any of them in its own "Edit brand header" pop-up
         * (App\Support\BrandPanel). On a phone the name's place is the capsule's
         * (brand_pill_at, above).
         */
        'brand_logo_show' => ['bool', 'Panel · laptop · show the brand logo', false,
            'Off, as you asked: the panel shows the brand name and the description, with no logo and no empty space where it was. On: the logo comes back, beside the name.'],
        'brand_logo_show_m' => ['bool', 'Panel · phone · show the brand logo', false,
            'Off, as you asked: the capsule on the banner holds the brand name alone. On: the logo comes back, beside the name.'],
        'brand_name_align' => ['select', 'Panel · laptop · brand name alignment', 'center',
            'Only for the Panel header, on laptops: the brand name (with the logo, when it shows) inside the panel. Centre, as you asked.',
            [
                'center' => 'Centre',
                'left' => 'Left',
                'right' => 'Right',
            ]],
        'brand_desc_align' => ['select', 'Panel · laptop · description alignment', 'center',
            'Only for the Panel header, on laptops: the description\'s lines inside the panel. Centre, as you asked.',
            [
                'center' => 'Centre',
                'left' => 'Left',
                'right' => 'Right',
            ]],
        'brand_desc_align_m' => ['select', 'Panel · phone · description alignment', 'center',
            'Only for the Panel header, on phones: the description\'s lines in the card below the banner. Centre, as you asked.',
            [
                'center' => 'Centre',
                'left' => 'Left',
                'right' => 'Right',
            ]],
        'brand_desc_lines' => ['range', 'Panel · laptop · description lines before "Read more"', 2,
            'Only for the Panel header, on laptops: a longer description is cut at this many lines, with "Read more" under it to open the rest in place and "Read less" to close it. Two, as you asked.',
            ['min' => 1, 'max' => 6, 'step' => 1, 'unit' => '']],
        'brand_desc_lines_m' => ['range', 'Panel · phone · description lines before "Read more"', 2,
            'Only for the Panel header, on phones: the same, in the card below the banner. Two, as you asked.',
            ['min' => 1, 'max' => 6, 'step' => 1, 'unit' => '']],
        /*
         * The header's own outer spacing (owner, 6 Oct, a phone screenshot with
         * arrows above it and at both sides: "on the brand's header setting
         * page, i need the spacing controls overall as marked"). 22px is what
         * the page already leaves on every side (.brw's padding), so these
         * ship at 22 and move nothing until he moves them. Less pulls the
         * header up / out to the screen edge; more pushes it in.
         */
        'brand_space_top' => ['range', 'Panel · laptop · space above the header', 22,
            'Only for the Panel header, on a laptop: from the menu bar down to the header.',
            ['min' => 0, 'max' => 80, 'step' => 1, 'unit' => 'px']],
        'brand_space_x' => ['range', 'Panel · laptop · space at the sides of the header', 22,
            'Only for the Panel header, on a laptop: from the page edge to the header, both sides.',
            ['min' => 0, 'max' => 80, 'step' => 1, 'unit' => 'px']],
        'brand_space_top_m' => ['range', 'Panel · phone · space above the header', 22,
            'Only for the Panel header, on phones: from the search bar down to the header.',
            ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px']],
        'brand_space_x_m' => ['range', 'Panel · phone · space at the sides of the header', 22,
            'Only for the Panel header, on phones: from the screen edge to the header, both sides. 0 runs it edge to edge.',
            ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],

        /*
         * ── PRESS FEEDBACK ──────────────────────────────────────── Lane RD ──
         *
         * "when u click on any button or icon. it leaves gray square /
         *  rectangle box instead of changing the color of button icon itself.
         *  it give feeling that we put just png images and clickable."
         *
         * The grey box is the browser's own tap highlight: the storefront never
         * set `-webkit-tap-highlight-color`, so every tapped control got the
         * default -- measured in Chromium's phone emulation on the header icons,
         * the heart, Add to cart and the pills: rgba(51,181,229,.4) at 390px and
         * rgba(0,0,0,.18) at 1280px, grey on an iPhone. And no control had a
         * pressed state of its own; the header icons had a :hover tint and
         * nothing else.
         *
         * He was shown five styles on a playground and answered: "set C · Ripple
         * by default, and give other as options to set from backend." So it
         * SHIPS AT `c`, in the code and not only on his shop -- a default that
         * lives only in a migration is a default a fresh install never sees,
         * and he has just complained about exactly that. CLAUDE.md rule 1's
         * 30 September reversal: a thing he asked for is the shop's new state.
         *
         * `off` is the shop exactly as it was: no attribute on <html>, so not
         * one byte of any page changes, and the stylesheet's rules -- every one
         * keyed by that attribute -- match nothing. See pressAttribute().
         */
        'press' => ['select', 'When a shopper taps a button or icon', 'c',
            'Every choice except Off removes the grey box the phone draws over whatever was tapped. They differ only in what the button itself does. Which buttons respond is the next choice.',
            self::PRESS_OPTIONS],

        /*
         * ── AND ONLY ON SMALL THINGS ───────────────────────────────── Lane FP ──
         *
         * The owner, on C: "the click tap is giving some background color in
         * area of click, that we did for header and small icons things, but i
         * don't want in mobile menu, in search box and other big stuff. it's
         * good only for small things like icons etc."
         *
         * So it SHIPS AT `icons` (he asked; CLAUDE.md, 30 September): the style
         * above lands on the icon buttons only -- the header icons, the burger,
         * every ×, the hearts, the − / + buttons, the arrows, the column buttons
         * and the share button. Everything else -- the menu rows, the search box,
         * the filter rows, product cards, Add to cart, pills, banners -- gets
         * nothing at all, and still no grey box. `all` is the shop as Lane RD
         * shipped it, one click away.
         *
         * It is an ALLOWLIST, the ICONS list kbb.css already styles, and it is
         * applied where the press begins: resources/js/kbb/press.js only ever
         * marks an element on that list, so no rule for a bigger control can
         * match. PressFeedbackScopeTest holds the two lists equal.
         */
        'press_scope' => ['select', 'Which controls respond', 'icons',
            'Small icons only, the default: the header icons, the menu button, every ×, the hearts, − / +, the arrows and the share button. The mobile menu, the search box, the filter rows, product cards and big buttons show nothing when tapped — and no grey box either. "Every button and row" is how it was before.',
            self::PRESS_SCOPES],

        /*
         * ── FONTS (Lane FS) ─────────────────────────────────────────────────
         *
         * The owner: "prepare a full list of fonts to include in our app, so
         * we can use as per need". Both ship at 'outfit', which is the shop
         * today, so applying the package moves nothing; App\Support\SiteFonts
         * prints nothing and the layout's Outfit preload is the bytes it was.
         * The option set IS App\Support\FontLibrary::LABELS, so the cast
         * refuses a family the library does not carry (rule 5).
         */
        'font_body' => ['select', 'Body font', 'outfit',
            'Every paragraph, price, button and product name in the shop. This is the one font the shop preloads, so the first words paint in it.',
            \App\Support\FontLibrary::LABELS],
        'font_heading' => ['select', 'Headings font', 'outfit',
            'Every heading — page titles, section headings, card titles. A homepage section can still pick its own on its Fonts & size tab.',
            \App\Support\FontLibrary::LABELS],
    ];

    /**
     * The press styles, lettered as they were on the owner's playground, so
     * "C" on the screen is the "C" he chose. (Lane RD)
     */
    public const PRESS_OPTIONS = [
        'off' => 'Off · the phone’s own grey box (how the shop was)',
        'a' => 'A · Soft fill — a blush circle, the icon turns pink, a small press-in',
        'b' => 'B · Pink pop — a solid pink circle with a white icon; buttons press in',
        'c' => 'C · Ripple — a pink wave spreads out from the middle',
        'd' => 'D · Bounce — squeezes in under the finger and springs back',
        'e' => 'E · Glow ring — a soft pink halo around what was pressed; nothing moves',
    ];

    /**
     * WHAT EACH STYLE PUTS ON <html>, AND THE ONLY THING IT PUTS THERE.
     *
     * Rule 5: this is printed unescaped into the layout's <html> tag, so it is a
     * constant -- the setting chooses a KEY of this map, never a byte of the
     * markup. `off` is the empty string, so a shop on Off renders the tag it
     * always did, byte for byte. (Lane RD)
     */
    public const PRESS_ATTR = [
        'off' => '',
        'a' => ' data-press="a"',
        'b' => ' data-press="b"',
        'c' => ' data-press="c"',
        'd' => ' data-press="d"',
        'e' => ' data-press="e"',
    ];

    /**
     * Which controls the press style reaches. (Lane FP) `icons` prints nothing
     * extra on <html>; `all` adds the constant ` data-press-all`, which
     * press.js reads to widen what it marks. See pressAttribute().
     */
    public const PRESS_SCOPES = [
        'icons' => 'Small icons only — header icons, ×, hearts, − / +, arrows',
        'all' => 'Every button and row, the menu and the search box too (how it was)',
    ];

    /** Not CSS on :root: skipped by isDefault(), never in css(). (Lane RD, FP) */
    private const PRESS_KEYS = ['press', 'press_scope'];

    /** Lane FS: read by App\Support\SiteFonts, not by css() — see isDefault(). */
    public const FONT_KEYS = ['font_body', 'font_heading'];

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

    /**
     * The most products a brand page draws at once when "Show every product of
     * the brand on one page" is on. Far above the largest brand; past it the
     * arrows carry the rest, so no brand can become one enormous page.
     */
    public const BRAND_ALL_CAP = 500;

    /** The brand page's switches: not CSS, skipped by isDefault(). */
    public const BRAND_KEYS = ['brand_all', 'brand_cta', 'brand_popular', 'brand_hero', 'brand_ring', 'brand_phone_cover',
        'brand_panel_style', 'brand_pill', 'brand_logo_shape', 'brand_header_w', 'brand_banner_h', 'brand_banner_h_m',
        'brand_content_w', 'brand_img_pos',
        // Lane BR3: the panel's box, position and type, laptop then phone.
        'brand_panel_x', 'brand_panel_y', 'brand_panel_pad', 'brand_panel_inset', 'brand_desc_gap', 'brand_name_fs',
        'brand_desc_fs', 'brand_logo_size', 'brand_pill_at', 'brand_pill_inset_m', 'brand_card_gap_m', 'brand_card_pad_m',
        'brand_name_fs_m', 'brand_desc_fs_m', 'brand_logo_size_m',
        // Lane BR4: the logo switch, the alignment and Read more, laptop then phone.
        'brand_logo_show', 'brand_logo_show_m', 'brand_name_align', 'brand_desc_align', 'brand_desc_align_m',
        'brand_desc_lines', 'brand_desc_lines_m',
        // 6 Oct: the header's outer spacing, laptop then phone.
        'brand_space_top', 'brand_space_x', 'brand_space_top_m', 'brand_space_x_m'];

    /** The keys that are not CSS: skipped by isDefault(), never in css(). */
    private const LOAD_KEYS = ['load_mode', 'load_batch', 'load_batch_custom', 'load_url'];

    /** The category title header's keys (Lane PT): not CSS either, for the same reason. */
    public const HEADER_KEYS = [
        // What it is, where it shows, and how it looks (Lane PY's first tab).
        'cat_header', 'cat_header_box', 'cat_header_fallback', 'cat_header_brands', 'cat_header_box_brands',
        'cat_header_align', 'cat_header_treatment', 'cat_header_box_treatment', 'cat_header_overlay',
        'cat_header_box_style', 'cat_header_box_bg', 'cat_header_box_icon', 'cat_header_text',
        'cat_header_valign', 'cat_header_generic',
        // Lane QC: the laptop's six, then the fine-tuning of each design.
        ...self::QC_LOOK_KEYS,
        // Sizes and spacing (the second).
        'cat_header_title_phone', 'cat_header_title_desktop', 'cat_header_weight',
        'cat_header_desc_phone', 'cat_header_desc_desktop', 'cat_header_lines', 'cat_header_more', 'cat_header_maxw',
        'cat_header_h_phone', 'cat_header_h_desktop',
        'cat_header_pad_y_phone', 'cat_header_pad_y_desktop', 'cat_header_pad_x_phone', 'cat_header_pad_x_desktop',
        'cat_header_radius', 'cat_header_mt_phone', 'cat_header_mt_desktop', 'cat_header_mb_phone', 'cat_header_mb_desktop',
    ];

    /**
     * Lane QC's keys on the look tab: the laptop's five choices, then the
     * fine-tuning. In SCHEMA order, which is the order the screen lists them.
     */
    public const QC_LOOK_KEYS = [
        'cat_header_align_desktop', 'cat_header_treatment_desktop', 'cat_header_box_treatment_desktop',
        'cat_header_box_style_desktop', 'cat_header_text_desktop', 'cat_header_valign_desktop',
        'cat_header_blush_bg', 'cat_header_blush_ic', 'cat_header_cream_bg', 'cat_header_cream_ic',
        'cat_header_mint_bg', 'cat_header_mint_ic', 'cat_header_lilac_bg', 'cat_header_lilac_ic',
        'cat_header_icon_strength', 'cat_header_icon_size',
        'cat_header_shadow_strength', 'cat_header_shadow_blur',
        'cat_header_fade_dark', 'cat_header_fade_reach',
        'cat_header_frost_opacity', 'cat_header_frost_blur', 'cat_header_frost_radius',
        'cat_header_label_bg', 'cat_header_label_fg',
        'cat_header_letter', 'cat_header_desc_colour',
        // 2.60.352: the random light box and its mix.
        'cat_header_box_random', 'cat_header_rand_blush', 'cat_header_rand_cream',
        'cat_header_rand_mint', 'cat_header_rand_lilac', 'cat_header_rand_plain',
        'cat_header_phone_whole',
    ];

    /**
     * PHONE KEY => LAPTOP KEY. (Lane QC) all() gives the laptop key the
     * phone's value while the laptop has none stored -- see the note above
     * `cat_header_align_desktop`.
     */
    public const DEVICE_PAIRS = [
        'cat_header_align' => 'cat_header_align_desktop',
        'cat_header_treatment' => 'cat_header_treatment_desktop',
        'cat_header_box_treatment' => 'cat_header_box_treatment_desktop',
        'cat_header_box_style' => 'cat_header_box_style_desktop',
        'cat_header_text' => 'cat_header_text_desktop',
        'cat_header_valign' => 'cat_header_valign_desktop',
    ];

    /**
     * The four icon boxes whose colours are settings, and their colours as
     * drawn -- the same two hex values each `.kbb-th--box-*` rule in
     * kbb-title-header.css carries. (Lane QC)
     */
    public const BOX_PRESETS = [
        'blush' => ['#FDF0F4', '#E3A1B5'],
        'cream' => ['#FBF4EA', '#CDA57B'],
        'mint' => ['#EEF8F2', '#8FC9AB'],
        'lilac' => ['#F4F0FB', '#B7A3DD'],
    ];

    /** The look tab of the category header. (Lane PY) */
    public const HEADER_LOOK_KEYS = [
        'cat_header', 'cat_header_box', 'cat_header_fallback', 'cat_header_brands', 'cat_header_box_brands',
        'cat_header_align', 'cat_header_treatment', 'cat_header_box_treatment', 'cat_header_overlay',
        'cat_header_box_style', 'cat_header_box_bg', 'cat_header_box_icon', 'cat_header_text',
        'cat_header_valign', 'cat_header_generic',
        // Lane QC: the laptop's six, then the fine-tuning of each design.
        ...self::QC_LOOK_KEYS,
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
            ['tile', 'tile_shop', 'cols_floor', 'cols_cap', 'gap', 'pin', 'show_count', 'filters_d', 'shop_links', 'sold_out', 'filter_w', 'filter_max', 'filters_m', 'cols_m']],
        'loading' => ['Loading more products',
            'How /shop, every category, every brand page and the curated listings bring in more products: more on scroll, numbered arrows, or everything at once. Shoppers without JavaScript always get the arrows.',
            ['load_mode', 'load_batch', 'load_batch_custom', 'load_url']],
        'catheader' => ['Category header',
            'The top of every category page: its title and description over the category\'s picture, or in a light box when it has none. Each category can change its own picture, title, description, sizes and look in Catalog → Categories → Edit → Category header; anything left blank there follows this screen.',
            self::HEADER_LOOK_KEYS],
        'catheadersize' => ['Category header · sizes & spacing',
            'Title and description sizes, the header\'s height, the space inside and around it, and its corners -- for phones and for laptops (900px and wider) separately.',
            self::HEADER_SIZE_KEYS],
        'brandpage' => ['Brand page',
            'What a brand\'s own page shows above its products, and whether it lists them all at once.',
            self::BRAND_KEYS],
        'press' => ['Press feedback',
            'What every button and icon in the shop does under a finger or a click. Tap the samples below to feel each one before you save; nothing changes on the shop until you press Save.',
            self::PRESS_KEYS],
        'fonts' => ['Fonts',
            'The shop\'s typefaces, from its own font library — twenty-nine families served from this shop, never from Google. Only the fonts you pick are loaded, and only the body font is preloaded.',
            self::FONT_KEYS],
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
        'filter_max' => '--kbb-fdrawer-max',
    ];

    /** @var array<string, string> */
    private const UNITLESS_VARS = [
        'cols_floor' => '--kbb-cols-floor',
        'cols_cap' => '--kbb-cols-cap',
        'filter_w' => '--kbb-fdrawer-w',
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

        /*
         * Lane QC: the four icon boxes' own colours, the same dialect. And the
         * three colours whose blank means "Automatic" -- the design as drawn --
         * get a rule instead: '' stays '', a colour is expanded and upper-cased
         * exactly as `expand` does, and anything else is null, which this
         * screen's `invalid => reject` reports as a 422 naming the field.
         */
        foreach (array_keys(self::BOX_PRESETS) as $box) {
            $out['cat_header_'.$box.'_bg']['hex'] = 'expand';
            $out['cat_header_'.$box.'_ic']['hex'] = 'expand';
        }

        foreach (['cat_header_label_bg', 'cat_header_label_fg', 'cat_header_desc_colour'] as $key) {
            $out[$key]['rule'] = static fn (mixed $raw): ?string => self::optionalColour($raw);
        }

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
        $stored = [];

        foreach (self::normalised() as $key => $field) {
            $saved = $this->settings->get($field['alias'], null);
            $stored[$key] = $saved !== null;

            $out[$key] = $saved === null
                ? $field['default']
                : (ModuleSchema::cast($field, $saved) ?? $field['default']);
        }

        /*
         * Lane QC: a laptop choice nobody has saved is the phone's choice, so
         * the single value a shop saved before phone and laptop were separate
         * is both devices' value. See DEVICE_PAIRS.
         */
        foreach (self::DEVICE_PAIRS as $phone => $laptop) {
            if (isset($out[$phone]) && ! ($stored[$laptop] ?? false)) {
                $out[$laptop] = $out[$phone];
            }
        }

        return $out;
    }

    /**
     * A colour that may be blank (Lane QC): '' for blank, #RRGGBB for #rgb or
     * #rrggbb, null for anything else.
     */
    public static function optionalColour(mixed $raw): ?string
    {
        if ($raw === null) {
            return '';
        }

        if (! is_string($raw)) {
            return null;
        }

        $clean = strtoupper(trim($raw));

        if ($clean === '') {
            return '';
        }

        if (preg_match('/^#([0-9A-F]{3})$/', $clean, $m) === 1) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9A-F]{6}$/', $clean) === 1 ? $clean : null;
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
            if (in_array($key, self::LOAD_KEYS, true) || in_array($key, self::HEADER_KEYS, true)
                || in_array($key, self::PRESS_KEYS, true) || in_array($key, self::BRAND_KEYS, true)
                || in_array($key, self::FONT_KEYS, true) || $key === 'sold_out') {
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
     * How many products a brand page asks for: everything (to BRAND_ALL_CAP)
     * when "Show every product of the brand on one page" is on, otherwise what
     * perPage() gives every other listing.
     */
    public function brandPerPage(int $arrows): int
    {
        return $this->get('brand_all') ? self::BRAND_ALL_CAP : $this->perPage($arrows);
    }

    /**
     * The press style in force: always a key of PRESS_OPTIONS. (Lane RD)
     *
     * all() already falls back to the default for a stored value cast() would
     * refuse; the second check is for a row written behind the screen's back
     * (a raw UPDATE, an import), which must still render the shipped style
     * rather than an attribute nobody can read.
     */
    public function press(): string
    {
        $press = (string) $this->get('press');

        return array_key_exists($press, self::PRESS_ATTR) ? $press : (string) self::SCHEMA['press'][2];
    }

    /**
     * The attribute the storefront layout prints on <html>: a constant from
     * PRESS_ATTR, '' for Off. (Lane RD)
     */
    public function pressAttribute(): string
    {
        $press = $this->press();

        /*
         * Lane FP: ` data-press-all` -- a constant -- only when the owner has
         * chosen "Every button and row" AND a style is on. The shipped `icons`
         * adds nothing, so the <html> tag is the bytes it was.
         */
        return self::PRESS_ATTR[$press]
            .($press !== 'off' && (string) $this->get('press_scope') === 'all' ? ' data-press-all' : '');
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
