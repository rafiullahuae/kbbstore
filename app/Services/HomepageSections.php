<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GridSkins;

/**
 * Which homepage sections render, and how.
 *
 * Each section can be switched off independently for desktop and for mobile,
 * and product sections carry their own grid skin.
 *
 * Visibility is applied with CSS classes rather than by sniffing the user
 * agent, so a cached page stays correct on every device. A section switched
 * off for both is not rendered at all, which also skips its queries.
 */
class HomepageSections
{
    /** key => [label, description, has a product grid, default skin] */
    /*
     * ── THE FOUR RAILS' OWN SKINS NOW FOLLOW THE SHOP'S ───────── Lane PG2 ──
     *
     * The fourth element of a row is that section's DEFAULT CARD STYLE, and the
     * four product rails carried four different ones — `classic`, `soft`,
     * `luxe` and `ribbon`. They are `GridSkins::DEFAULT` now, because the owner
     * asked for one card "on the whole website everywhere" and the homepage is
     * the one place that was deliberately exempt from the store-wide setting:
     * these rails CHOOSE their own, which is exactly what "used wherever a grid
     * does not choose its own" excludes.
     *
     * A SHOP THAT HAS ALREADY SAVED A HOMEPAGE KEEPS WHAT IT SAVED. all() reads
     * the stored payload first and falls back to the registry, so this moves the
     * rails on a shop that has never opened Appearance → Homepage and nothing
     * on one that has. Either way each rail's picker is still there and still
     * offers all 32.
     */
    public const REGISTRY = [
        /*
         * ── ONE ROW ADDED BY LANE BN, AND IT DRAWS NOTHING ON APPLY ─────────
         *
         * The owner: "I need multiple cards type with auto scroll smooth
         * scroll ... we can turn on off card banners ... full control options
         * to choose which banner will show on homepage".
         *
         * It sits HERE, and the position is the template's: this list IS the
         * default order, and a key whose position here disagrees with
         * store/home.blade.php would hand Appearance -> Homepage a picture the
         * shop does not draw. That is the fault HomepageSections::settle()
         * exists to stop one level along.
         *
         * ▲ IT MOVED FROM AFTER THE HERO BAND TO BEFORE IT.          (Lane SEC)
         *
         * "the banner i need to change to simple image banners, not cards,
         * simple only images banner". The hero cannot become an image banner —
         * App\Services\HomepageContent has no image field at all, a hero slide
         * being two gradients and three lines of text — so the answer is to
         * draw the picture banner WHERE THE HERO WAS and stand the hero's
         * rotation down while it does. store/home.blade.php moved the section
         * with it and gates `$heroCarriesH1` on `$bnSection === null`, so the
         * page carries ONE banner and never two.
         *
         * AND IT STILL CHANGES NO BYTE ON A SHOP WITH NO BANNER PICTURES,
         * which is the half that matters. `order` is the registry index for
         * every row, so moving a key leaves orderIsDefault() true and
         * orderStyle() returning '' — no CSS is emitted either way — and the
         * section's element is inside the @if, so the moved block emits nothing
         * from its new place exactly as it emitted nothing from its old one.
         * StorefrontEnglishUnchangedTest is the instrument and it is green.
         *
         * IT COSTS AN UNCONFIGURED SHOP NOTHING. A key added to this list
         * changes no byte on its own: `order` is the registry index for every
         * row, so orderIsDefault() is still true and orderStyle() still returns
         * '', and the section's own element is drawn INSIDE the @if in
         * store/home.blade.php rather than around it — so a shop with the
         * module off, or with no set chosen, emits exactly what it emitted
         * before. StorefrontEnglishUnchangedTest is the instrument and
         * CardsBannerShipsOffTest is the argument.
         *
         * Its Desktop and Mobile switches still work: the @unless on the
         * template is kept as well, and it short-circuits before any read.
         */
        /*
         * ── TWO STRIPS, PHONES ONLY BY DEFAULT (Lane HC) ─────────────────────
         *
         * The owner, of the old shop on a phone: "ONLY FOR MOBILE: turn this off
         * in laptop by default. i need the the top bar strip, same color, same
         * text and size etc. and below the main banner, i need that countries
         * strip ... these will come as sections on homepage content".
         *
         * `topstrip` is FIRST because it sits directly under the header and
         * search. `countries` is the shop's existing flag bar (Appearance →
         * Header → Flag bar keeps its words, colours and sizes — one copy), and
         * it sits where store/home.blade.php has drawn that bar since Lane SEC:
         * under the picture banner. Both ship ON for phones and OFF for laptops
         * (MOBILE_ONLY_BY_DEFAULT), hidden on a laptop by the stylesheet rather
         * than by printing a different document — the page has one HTML.
         */
        'topstrip'    => ['Top strip', 'The thin coloured line under the header: delivery and the free-delivery threshold. Phones only by default. Words, colours, size and link: Homepage content → Top strip.', false, null],
        'cards_banner' => ['Banners', 'The picture banner at the top of the page: one image per slide, sliding when there is more than one. Build the sets in Appearance → Banners and pick which one shows; nothing shows until you do.', false, null],
        'countries'   => ['Countries strip', 'The UAE flag, “UAE’s Authentic K-Beauty Store” and the Korean flag, under the banner. Phones only by default. Its words, colours and sizes are the Flag bar’s: Appearance → Header → Flag bar.', false, null],
        'hero'        => ['Hero slider', 'The rotating coloured panels with a headline and a button. Drawn only when the Banners section above has no pictures to show — a shop with a picture banner has one banner, not two.', false, null],
        'delivery'    => ['Delivery strip', '1-3 days delivery, free over AED 199.', false, null],
        'ticker'      => ['Promo ticker', 'The scrolling discount-code line.', false, null],
        'categories'  => ['Category circles', 'Shop by category, scrollable.', false, null],
        'bundles'     => ['Big savings bundles', 'Skincare sets and routines.', true, GridSkins::DEFAULT],
        /*
         * ── ROW 55 (Lane HA): THE OWNER'S NEW HOMEPAGE, SECTIONS 2–9 ────────
         *
         * "In this order": bundles, Best Sellers, Brands, #KBeautyBliss
         * Spotted, Trending, Blog, Under AED 54, a two-column feature, About
         * us. The four new keys sit where the template draws them, so with the
         * sections OFF_BY_DEFAULT lists switched off the visible sequence IS
         * that order without moving one existing key — `brands`, `spotted`,
         * `blog` and `about` already ran in that order. Each new row is
         * `false` for a grid: the card is the shop's own, at the shop's own
         * skin ("the grid cards design must not be changed"), so this screen
         * offers no picker for it. Their words, products, columns, background
         * and spacing are Appearance → Homepage content → the section's tab.
         */
        'bestselling' => ['Best Sellers', 'Best-selling grid: 8 on a laptop, 6 on a phone, with the Shop best sellers button. Words, products and spacing: Homepage content → Best Sellers.', false, null],
        'recommended' => ['Recommended for you', 'Handpicked essentials.', true, GridSkins::DEFAULT],
        'routine'     => ['Build your routine', 'The six-step routine.', false, null],
        'quiz'        => ['Skin quiz', 'The two-minute routine finder.', false, null],
        'brands'      => ['Top brands', 'Brand photo cards on a laptop, logos on a phone. Which brands: Homepage content → Brands.', false, null],
        'spotted'     => ['#KBeautyBliss spotted', 'Shoppable community photos.', false, null],
        /*
         * ── TWO ROWS ADDED BY LANE IG, AND BOTH DRAW NOTHING ON APPLY ───────
         *
         * The owner: "Also make the Videos rail section on homepage and let us
         * choose the section to show from the list or use shortcode" and "i want
         * another function called Instagram Profile".
         *
         * They sit HERE, immediately after `spotted`, and the position is a
         * decision rather than an accident. docs/UGC-RAIL-R3.md §9 argued that a
         * shoppable-video rail belongs where `spotted` already promises
         * "shoppable community photos" rather than twenty lines from it; this is
         * that argument honoured without hard-coding a handle — see
         * docs/UGC-RAIL-R3.md §9 and this lane's report for why the swap it
         * proposed was NOT taken.
         *
         * ▲ INSERTING MID-REGISTRY DOES NOT REORDER A SHOP THAT HAS SAVED AN
         * ORDER, and that is worth the line because it looks as though it would.
         * all() reads `$row['order'] ?? $order`, so a saved payload's own
         * numbers win and only these two new keys fall back to their registry
         * index — 10 and 11, which TIE with whatever the owner's payload has at
         * 10 and 11. uasort() is stable as of PHP 8.0, so a tie keeps REGISTRY
         * iteration order, which puts these two exactly where they are written
         * and leaves every other row's relative position untouched. settle()
         * then renumbers 0..n-1. Pinned by HomepageSectionOrderTest's
         * `it inserts a new registry section without reordering a saved payload`.
         *
         * Both render NO BYTES until the owner configures them, so
         * StorefrontEnglishUnchangedTest cannot move on apply: store/home.blade.php
         * gates the whole <section> on there being content, not on the switch.
         * The switches therefore ship ON, like the sixteen beside them, because
         * an off switch on a section that draws nothing anyway is a second
         * thing to remember to turn on.
         */
        'videos'      => ['Video rail', 'A shoppable video rail. Pick which section in Content → Shoppable video → Appearance → Homepage; nothing shows until you do.', false, null],
        'instagram'   => ['Instagram Profile', 'Recent posts and reels from our own Instagram, with the profile box. Connect it in Content → Instagram; nothing shows until you do.', false, null],
        /*
         * (Lane IGE) Pasted Instagram posts and reels, drawn with Instagram's own
         * embed — no API, no login. Directly after the API-based Instagram
         * Profile row it replaces in the owner's plan, by the same insertion
         * argument the two rows above make: a saved order keeps every other row
         * where it is. It renders NO BYTES until a post is pasted and switched
         * on, so the switch ships ON (the owner asked for the system).
         */
        'igembeds'    => ['Instagram embeds', 'Instagram posts and reels you paste by address, shown with Instagram’s own player. Add them in Content → Instagram embeds; nothing shows until you do.', false, null],
        'trending'    => ['Trending', 'What is moving this week: 8 on a laptop, 6 on a phone. Words, products and spacing: Homepage content → Trending.', false, null],
        'bestsellers' => ['Best sellers', 'Ranked by sales this month.', true, GridSkins::DEFAULT],
        'flash'       => ['Flash sale', 'Discounted, with stock remaining.', true, GridSkins::DEFAULT],
        'blog'        => ['Skincare guide', 'Three journal articles. Words and which articles: Homepage content → Blog.', false, null],
        'under54'     => ['Under AED 54', '10 on a laptop, 6 on a phone, at or under the price ceiling. Homepage content → Under AED 54.', false, null],
        'feature'     => ['Two-column feature', 'Two photo panels — Sunscreens and best sellers. Photos, words and links: Homepage content → Two-column feature.', false, null],
        'about'       => ['About us', 'The About us text, last on the page. Homepage content → About us.', false, null],
        'reviews'     => ['Customer reviews', 'Score summary and review cards.', false, null],
        'trust'       => ['Trust row', 'Shipping, payments, authenticity, support.', false, null],
        'newsletter'  => ['Newsletter', 'Ten percent off the first order.', false, null],
    ];

    /**
     * THE REGISTRY THE REST OF THIS CLASS READS: the const above, plus one row
     * per grid section the owner has built (Lane GS).
     *
     * ── WHY THE CONST COULD NOT SIMPLY GROW A ROW ───────────────────────────
     *
     * The owner asked for ONE section type he can add as many times as he
     * likes — "we can re-use this grid section anywhere multiple times with
     * different products etc selection". There is therefore no fixed number of
     * sections any more, and `REGISTRY` is a `const`. Every other property this
     * screen has — the saved order, the Desktop and Mobile switches, the
     * dividers, the presets, `settle()`'s renumbering — is keyed off whatever
     * this method returns, so returning the const plus the built instances
     * gives an instance ALL of it and adds no second mechanism beside it. That
     * is the thing this file's own comments say the project has already paid
     * for elsewhere, and it would surface here as two screens disagreeing about
     * where a section sits.
     *
     * ── IT COSTS AN UNCONFIGURED SHOP NOTHING ───────────────────────────────
     *
     * With no instances built, `GridSections::registryRows()` returns `[]` and
     * `self::REGISTRY + []` is `self::REGISTRY` — the same keys in the same
     * order, so `isDefaultOrder()` is unchanged, `orderStyle()` still returns
     * `''`, and the page emits not one extra byte. That is the same argument
     * the `cards_banner` row above makes, one level further along.
     *
     * ── `+` AND NOT array_merge(), WHICH IS NOT A STYLE CHOICE ──────────────
     *
     * Both preserve string keys, but `+` keeps the LEFT operand's value on a
     * collision and `array_merge()` keeps the right's. The const must win: a
     * `grid_*` key cannot collide with a shipped one today, and if a future
     * release ever shipped a section whose key an instance already held, the
     * shipped section is the one the template draws and the instance is the one
     * that would silently take its place on the page.
     *
     * ── THE INSTANCES ARE APPENDED, AND THE TEMPLATE AGREES ─────────────────
     *
     * They land AFTER the seventeen, and store/home.blade.php draws its loop
     * after `newsletter` for exactly that reason: this list IS the default
     * order, and a key whose position here disagrees with the template would
     * hand Appearance → Homepage a picture the shop does not draw. The owner
     * moves an instance up the page with the ↑ on that screen, which is the
     * mechanism that already exists and which now emits the ordering rules for
     * it too.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string|null}>
     */
    public static function registry(): array
    {
        return self::REGISTRY + GridSections::registryRows();
    }

    /**
     * Sections whose markup is drawn INSIDE another section, and the host they
     * travel with.
     *
     * ── WHY THIS CONSTANT HAS TO EXIST ──────────────────────────────────────
     *
     * Ordering is applied with CSS `order`, which moves FLEX CHILDREN. Fifteen
     * of the seventeen sections are direct children of `.kbb-home` and move.
     * `delivery` and `ticker` are not: store/home.blade.php draws both inside
     * the hero's own `<section>`, under its `.wrap`, because the hero band is
     * one visual unit and the delivery strip and the promo ticker are the two
     * lines beneath its slider. `order` on a non-flex-child is INERT — the
     * declaration is accepted and does nothing at all.
     *
     * So this is not a limitation that can be hidden: an ↑ on those two rows
     * would be a button that moves a row on a screen and nothing on the shop,
     * which is the exact defect docs/FO-HOMEPAGE-INVENTORY.md was written to
     * catalogue. It is named here instead, carried into the payload the console
     * paints from, and — the half that matters — ENFORCED in all(), which keeps
     * each nested section pinned directly behind its host. The row order the
     * owner is shown is therefore the order the shopper gets, for all
     * seventeen, with no row that lies.
     *
     * Lifting the two out of the hero would make them movable and is the right
     * eventual shape, but it moves rendered bytes for every shop on the shipped
     * layout — see docs/FR-HOMEPAGE-ORDER.md, which costs it.
     */
    public const NESTED = [
        'delivery' => 'hero',
        'ticker' => 'hero',
    ];

    /**
     * Said on the row, in the owner's words, instead of an arrow that lies.
     *
     * THE THIRD CLAUSE USED TO BE ABOUT THE SWITCHES, AND IS NOT ANY MORE —
     * Lane FW. It read "and is hidden on any device the hero itself is
     * switched off for", which was true when Lane FR wrote it and is the
     * defect that lane measured and costed rather than fixed: the band is one
     * `<section>` carrying the HERO's visibility classes, so `d-off` on the
     * hero set `display:none` on the element these two are drawn inside and
     * their own Desktop/Mobile switches were overridden with nothing said.
     *
     * The band now takes bandClassFor(), the UNION of the three rows, and the
     * slider takes the hero's own — so these two rows' switches decide these
     * two rows, which is what the screen has always implied they do. What
     * remains true of them, and is all this sentence now claims, is that they
     * travel with the hero's POSITION: they are drawn inside its markup, so
     * CSS `order` cannot move them away from it.
     *
     * The clause is not replaced by a reassurance. A row that behaves the way
     * the screen's own controls say it does needs no sentence about it, and one
     * would only go stale in the other direction.
     */
    public const NESTED_NOTE = 'Drawn inside the hero band, so it moves with the hero and cannot be placed elsewhere on the page. Its own Desktop and Mobile switches still decide whether it shows.';

    /**
     * WHAT SITS BEHIND A HOMEPAGE SECTION, `token => label`.        (Lane BG)
     *
     * The owner: *"on homepage i want to remove the sections backgrounds by
     * default, and if i need it for any section, i can put it myself."*
     *
     * ── `off` IS THE DEFAULT, AND THAT IS A MOVED DEFAULT ───────────────────
     *
     * Every section on the home page drew a white panel — `rgba(255,255,255,
     * .94)` at 22px of radius with a border and a shadow, and a pink gradient
     * on the four `tinted` ones. This lane measured those panels covering
     * **100% of the first screen at 1280** (docs/BG-BACKGROUND-CANDIDATES.md),
     * which is why the background he picked barely showed. He read that and
     * asked for the panels to come off.
     *
     * So this ships at `off` rather than at what the page already draws, under
     * CLAUDE.md's 30-September reversal: *"whatever i said, keep applying on
     * the site … i want to apply such things directly to the site to save
     * time."* The control exists — PER SECTION, which is his second clause —
     * so any one of them can have its panel back without touching the rest.
     *
     * ── TWO OPTIONS AND NOT THREE ───────────────────────────────────────────
     *
     * `panel` is "the background this section always had", not "white": the
     * four sections the template marks `tinted` get their pink gradient back
     * and the rest get the white card, because the look is decided by a class
     * the template writes and this only says whether it paints. A third token
     * spelling "white, on a tinted section" would be a choice the screen offers
     * and the template cannot honour.
     *
     * HOME PAGE ONLY. These classes are read by `.kbb-home .sec` rules, and
     * `.kbb-home` is on four templates — but only store/home.blade.php draws
     * `.sec` elements, so /shop/, a product page, the cart and the journal
     * cannot be reached by any of this.
     */
    public const BACKGROUNDS = [
        'off' => 'None — the page’s own background shows through',
        'panel' => 'The section’s own panel — the card it used to draw',
    ];

    /**
     * HOW WIDE A SECTION RUNS, `token => label`.                    (Lane BG)
     *
     * The owner: *"any section i can make full width upto 1920x, give this
     * option and it must be auto adjusted to the screen sizes below 1920px
     * width"*.
     *
     * ── THE CAP IS `min(100%, 1920px)` AND IT IS SPELT WITHOUT `vw` ─────────
     *
     * The stylesheet writes `width:100%;max-width:1920px` on an element whose
     * containing block is the section, which is the page. That is the same
     * `min()` the site width already uses one level up (`--site-max`), and it
     * is deliberately NOT `min(100vw, 1920px)`: `100vw` includes the classic
     * scrollbar on a desktop browser, so a full-width section written that way
     * is a few pixels wider than the page and GROWS A HORIZONTAL SCROLLBAR —
     * which then makes the page narrower, which is the oscillation this
     * project has paid for elsewhere. `100%` is the page's real width at every
     * viewport, so there is no breakpoint to maintain and no width at which it
     * is undefined. Rule 4: nothing here is measured by script.
     *
     * ── THREE TOKENS, BECAUSE `full` AND `bleed` ARE DIFFERENT THINGS ───────
     *
     * `full` keeps the page's side gutter, so a section of TEXT set to full
     * width still has its words off the edge of the screen. `bleed` takes the
     * gutter off as well, which is what a picture or a coloured band wants and
     * what the banner needs to read as edge to edge. A single "full width"
     * token would have had to pick one, and picking `bleed` puts paragraphs
     * against the glass on a phone.
     *
     * ── `cards_banner` DEFAULTS TO `bleed`, WHICH IS A MOVED DEFAULT ────────
     *
     * *"and by default, make the main images banner full width"*. Everything
     * else defaults to `normal` — the width it has today — because rule 1's
     * other half still holds: what he did not ask about ships byte-identical.
     */
    public const WIDTHS = [
        'normal' => 'The page’s own width — 1680px, inset, as it is today',
        'full' => 'Full width — edge to edge up to 1920px, side gutter kept',
        'bleed' => 'Full bleed — edge to edge up to 1920px, no gutter at all (a picture or a band)',
    ];

    /**
     * The sections whose `width` ships at something other than `normal`.
     *
     * ONE KEY, and it is the banner. A map rather than a fifth element on the
     * REGISTRY rows because the registry's shape is read in eight places and
     * `[label, description, hasGrid, defaultSkin]` is destructured by position
     * in most of them — growing it to serve one key would have been eight
     * edits for one fact. `overridesFor()` reads this and nothing else does.
     *
     * @var array<string, string>
     */
    public const WIDTH_DEFAULTS = ['cards_banner' => 'bleed'];

    /**
     * THE SECTIONS THAT SHIP SWITCHED OFF, on both devices.      (Row 55, Lane HA)
     *
     * The owner, 3 October: "don't include anything from our existing homepage
     * on extreabeauty, except banner. we need the sections which i described
     * ... don't include reoutine builder etc, that's not finished yet". Every
     * section of the old page that is not one of his nine is listed here. NONE
     * IS DELETED: each keeps its code, its row on Appearance → Homepage and its
     * Desktop/Mobile switches, so any of them comes back with one click. The
     * hero stays on because it is the banner's fallback — it draws only when
     * the picture banner has nothing to show.
     *
     * A shop that has SAVED its homepage keeps what it saved, the way every
     * default in this class works — which is why
     * 2027_07_27_000100_clear_caches_home_row55_sections writes the same
     * answer into a saved payload: what he asked for ships on.
     *
     * @var list<string>
     */
    /**
     * Shown on phones and hidden on laptops until the owner says otherwise —
     * his words for both (Lane HC). Hidden by `d-off`, which the stylesheet
     * applies from 901px, so a laptop is sent the same document.
     */
    public const MOBILE_ONLY_BY_DEFAULT = ['topstrip', 'countries'];

    /** Thin strips: never "the first section", never given a divider. */
    public const STRIPS = ['topstrip', 'countries'];

    public const OFF_BY_DEFAULT = [
        'delivery', 'ticker', 'categories', 'recommended', 'routine', 'quiz',
        'videos', 'instagram', 'bestsellers', 'flash', 'reviews', 'trust', 'newsletter',
    ];

    /**
     * The three controls a section row carries, as ModuleSchema fields.
     *
     * ── WHY THE ROW GETS A SCHEMA AT ALL ────────────────────────────────────
     *
     * This screen is older than ModuleSchema and grew its own coercion: a pair
     * of `(bool)` casts in all(), the same pair again in save(), and
     * `GridSkins::exists($skin) ? $skin : $defaultSkin` written out twice. Four
     * copies of three rules, which is exactly the arrangement
     * docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of: the isValidHex
     * defect survived in four modules because there were four copies of the
     * same three lines and nothing tied them together.
     *
     * It matters more here than it did there, because a THIRD reader has just
     * arrived. `proposing()` below renders the homepage from a configuration
     * nobody has saved, and the only thing that makes such a preview worth
     * looking at is that it answers the same way the save would. Two copies of
     * the rules make that a promise; one cast makes it a fact — the preview and
     * the save are literally the same three lines of ModuleSchema::cast().
     *
     * `order` IS NOT IN HERE, and that is deliberate rather than an omission.
     * It is not a control: nothing on the screen types it, the console posts a
     * SEQUENCE and the server numbers it by position, and settle() rewrites it
     * on every read. A schema field is something an owner sets; a position is
     * something the list has.
     */
    public const SECTION_SCHEMA = [
        'desktop' => [
            'type' => 'bool',
            'label' => 'Show on desktop',
            'default' => true,
            'help' => 'Hidden above the mobile breakpoint when off. A section off for both is not rendered at all, so it costs no queries either.',
        ],
        'mobile' => [
            'type' => 'bool',
            'label' => 'Show on mobile',
            'default' => true,
            'help' => 'Hidden at or below the mobile breakpoint when off.',
        ],
        'skin' => [
            'type' => 'skin',
            'label' => 'Grid style',
            'default' => '',
            'help' => 'One of the product-grid card templates. Only the four sections that draw a product grid carry one.',
        ],
        /*
         * ── THE TWO LANE BG ADDED, AND THEY COST THE SCHEMA NOTHING ─────────
         *
         * `select` is a type ModuleSchema already casts, already validates
         * against its own `options` and already renders — so these two fields
         * are drawn by the same ModuleSchema::tabs() call the three above are,
         * and the live-edit screen picks them up with no line of it changing.
         * That is the thing the header over this constant argues for: one cast,
         * three readers, and a preview that answers the way the save does.
         *
         * SECTION_POLICY's `invalid => default` is what makes rule 5 hold here
         * — "a select stores one of its own options or the default". A value
         * that is not a key of BACKGROUNDS / WIDTHS cannot survive the read, so
         * a hand-edited settings row cannot put a token into a class name.
         */
        'background' => [
            'type' => 'select',
            'label' => 'Section background',
            'default' => 'off',
            'options' => self::BACKGROUNDS,
            'help' => 'The panel behind this section. Off everywhere on the home page — put it back here for any section that needs one.',
        ],
        'width' => [
            'type' => 'select',
            'label' => 'Section width',
            'default' => 'normal',
            'options' => self::WIDTHS,
            'help' => 'How wide this section runs. Full width is capped at 1920px and follows the screen at every width below it, down to 320px.',
        ],
    ];

    /**
     * The point this screen has always occupied on the policy axes, declared.
     *
     * `bool => cast` is the plain `(bool)` both readers already did — NOT the
     * word-aware dialect, which would read the string "off" as false where this
     * screen has always read it as true. `invalid => default` is
     * `GridSkins::exists($skin) ? $skin : $defaultSkin` restated: an unknown
     * skin falls back to the section's own default rather than being refused,
     * because a refusal on a read has no channel to report through and the page
     * still has to draw a grid.
     *
     * Both were established by running the old code over an adversarial corpus
     * before a line moved, not by reading it — see
     * tests/Feature/HomepageSectionSchemaTest.php, which keeps the old bodies
     * as literal expectations and drives several thousand calls through both — the
     * read case alone asserts it made more than 4,000.
     */
    public const SECTION_POLICY = ['bool' => 'cast', 'invalid' => 'default'];

    /**
     * The one group the three controls are drawn in — the `TABS` shape
     * ModuleSchema::tabs() reads (Lane HL).
     *
     * NINETEEN SECTIONS SHARE ONE GROUP, because they share one schema: a tab
     * here is a heading and a sentence over a set of keys, and there is exactly
     * one set. It exists so the live panel is drawn by the same
     * SCHEMA/TABS/POLICY call as every other settings screen in this console
     * rather than by a heading the screen writes out for itself — which is the
     * thing that goes stale the day a field is added.
     *
     * `skin` is named here for every section and DROPPED by sectionTabs() for
     * the fifteen that have no product grid, the same way castRow() nulls it
     * for them. One statement of which controls exist, one statement of which
     * sections carry them.
     */
    public const SECTION_TABS = [
        'placement' => [
            'Where it appears',
            'Which devices draw this section, and which card template its grid uses. A section off for both is not rendered at all, so it costs no queries either.',
            ['desktop', 'mobile', 'skin'],
        ],
        /*
         * A SECOND GROUP, because these two are a different question. The
         * first group is "does this section appear"; this one is "what shape is
         * it when it does", and a heading that ran the five together would be
         * the sentence that goes stale the day a sixth is added.
         *
         * Both are DROPPED by sectionTabs() for `delivery` and `ticker`, the
         * two rows drawn inside the hero's own <section>. They have no `.wrap`
         * of their own for either rule to reach, so offering the controls there
         * would be two dropdowns that do nothing — the fault CLAUDE.md names
         * three times and the reason NESTED_NOTE exists one row along.
         */
        'frame' => [
            'How it looks',
            'The panel behind this section and how wide it runs. Both are off the shipped page by default — the panels came off the home page and the picture banner runs edge to edge.',
            ['background', 'width'],
        ],
    ];

    /**
     * The class a section wrapper carries when it is drawn INTO A PREVIEW THE
     * CONSOLE CAN SELECT IN (Lane HL).
     *
     * Emitted by frameClass() and only on an annotating proposal — see
     * proposing() — so the shop, and the preview that promises to be
     * byte-identical to it, never carry it. The screen matches on
     * `SELECT_CLASS.'-'.$key` to learn which section a click landed in, which
     * is what lets selection be a class rather than a measurement: rule 4
     * forbids JavaScript that measures layout, and an outline drawn by a
     * stylesheet inside the frame follows its element at either viewport with
     * nothing to recompute.
     */
    public const SELECT_CLASS = 'kbb-pvsec';

    /**
     * A configuration this instance answers from INSTEAD of the stored one.
     *
     * Null on every instance the storefront and the console build, which is
     * every instance but the one preview() makes, so the shop reads the
     * settings table exactly as it did before this existed.
     *
     * @var array<string, mixed>|null
     */
    private ?array $proposed = null;

    /**
     * Whether this reader marks what it classes, for a console that has to
     * select in it (Lane HL).
     *
     * FALSE BY DEFAULT AND SETTABLE ONLY THROUGH proposing(), which is the
     * whole safety of it: the storefront never builds a proposal, so the shop
     * cannot emit a hook however this is called, and /admin-api/homepage/preview
     * does not opt in either — its document is still byte-identical to GET /,
     * which HomepagePreviewTest §1 asserts and HomepageLiveEditTest asserts
     * again from the other side.
     */
    private bool $annotate = false;

    public function __construct(private SettingsService $settings) {}

    /**
     * A reader for an arrangement NOBODY HAS SAVED.
     *
     * ── WHAT THIS IS FOR ────────────────────────────────────────────────────
     *
     * Both homepage screens publish straight to the live shop: the only way to
     * see what moving a section does was to save it and then go and look, on
     * the shop real visitors are on. That is the whole of what "live editing"
     * was missing here — not a control, a LOOK. This instance is the seam: the
     * admin posts the arrangement currently on screen, the homepage is rendered
     * through this reader instead of the stored one, and nothing is written.
     *
     * IT ANSWERS THROUGH THE SAME all(), WHICH IS THE POINT AND NOT A SHORTCUT.
     * The proposal is merged over the registry, cast through the same
     * SECTION_SCHEMA, sorted and settle()d by the same code the shop reads
     * through — so a preview cannot show an order the page would not draw
     * (settle() puts a nested row back behind its host here too) and cannot
     * show a skin the save would refuse. A second, simpler reader written for
     * the preview would be a third dialect, and this project has paid for every
     * dialect it has.
     *
     * `$annotate` IS THE LIVE EDITOR'S HALF AND IS OPT-IN (Lane HL). With it,
     * every wrapper this reader classes also carries SELECT_CLASS and a class
     * naming its section, so the console can tell which section a click inside
     * the preview landed in without measuring anything. It defaults to false
     * because the caller that must NOT have it — preview(), whose document is
     * pinned byte-identical to the shop's — is the caller that would get it by
     * accident.
     *
     * @param  array<string, mixed>  $proposed  the saved-payload shape:
     *         key => [desktop, mobile, skin, order]
     */
    public static function proposing(SettingsService $settings, array $proposed, bool $annotate = false): self
    {
        $reader = new self($settings);
        $reader->proposed = $proposed;
        $reader->annotate = $annotate;

        return $reader;
    }

    /** True when this instance is answering from a proposal rather than the shop. */
    public function isProposal(): bool
    {
        return $this->proposed !== null;
    }

    /**
     * The saved configuration, merged over the defaults.
     *
     * Merging rather than replacing means a section added in a later release
     * appears immediately and switched on, instead of vanishing because an
     * older saved payload never mentioned it.
     */
    public function all(): array
    {
        $saved = $this->proposed ?? $this->settings->get('homepage_sections');
        $saved = is_array($saved) ? $saved : [];

        $out = [];
        $order = 0;

        foreach (self::registry() as $key => [$label, $desc, $hasGrid, $defaultSkin]) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $cast = self::castRow($key, $row);

            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'has_grid' => $hasGrid,
                'skin' => $cast['skin'],
                'background' => $cast['background'],
                'width' => $cast['width'],
                'desktop' => $cast['desktop'],
                'mobile' => $cast['mobile'],
                'order' => (int) ($row['order'] ?? $order),
                // What the console needs to draw the row honestly. Both are
                // derived from NESTED rather than stored, so a saved payload
                // cannot disagree with the template.
                'nested_in' => self::NESTED[$key] ?? null,
                'movable' => ! isset(self::NESTED[$key]),
                'note' => isset(self::NESTED[$key]) ? self::NESTED_NOTE : null,
            ];

            $order++;
        }

        uasort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        return self::settle($out);
    }

    /**
     * Put each nested section back behind its host, then renumber.
     *
     * ── WHAT THIS IS FOR ────────────────────────────────────────────────────
     *
     * A saved payload can place `ticker` at position 16 — the Editorial preset
     * does exactly that, and did before this change. The template cannot honour
     * it: the ticker is drawn inside the hero's `<section>` and renders wherever
     * the hero renders. Left alone, all() would hand the console a list in which
     * two rows sit somewhere the shopper will never see them, the screen would
     * paint that list, and the owner would be told a position the page does not
     * have. That is the same "saved and never read" fault one level along.
     *
     * So the constraint is applied HERE, in the one reader both the console and
     * the storefront go through, rather than being described on the screen and
     * hoped for. A nested section always follows its host immediately; several
     * sharing a host keep REGISTRY order between them, which is the order the
     * hero's own markup draws them in and therefore the only order that is
     * true.
     *
     * `order` is then rewritten to the EFFECTIVE position, 0..n-1. That makes
     * the value idempotent: the console posts the key sequence back, save()
     * numbers it by position, and a second read returns the same list. A
     * preset that scattered the nested rows is normalised on the way out rather
     * than being re-saved behind the owner's back.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function settle(array $rows): array
    {
        $out = [];

        foreach (self::settleKeys(array_keys($rows)) as $key) {
            $out[$key] = $rows[$key];
        }

        $i = 0;

        foreach ($out as $key => $row) {
            $out[$key]['order'] = $i++;
        }

        return $out;
    }

    /**
     * The sequence a saved key order REALLY produces on the page.
     *
     * Split out of settle() so that a caller holding a bare list of keys can
     * ask the same question without inventing rows to ask it with — which is
     * what HomepageLayouts::summaries() was doing wrong. Its wire-frame preview
     * drew each preset's STORED sequence, and two of the four presets store a
     * sequence this method rewrites: Conversion puts the ticker before the
     * delivery strip and Boutique puts the delivery strip tenth. Applying
     * either produced a different page from the one the preview drew, which is
     * the same "the screen said one order and the shop rendered another" fault
     * settle() exists to end, one screen along.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function settleKeys(array $keys): array
    {
        $registry = array_keys(self::REGISTRY);

        // Hosts in their saved order; nested keys dropped out of the sequence.
        $hosts = array_values(array_filter($keys, fn ($k) => ! isset(self::NESTED[$k])));

        $out = [];

        foreach ($hosts as $host) {
            $out[] = $host;

            foreach ($registry as $key) {
                if ((self::NESTED[$key] ?? null) === $host && in_array($key, $keys, true)) {
                    $out[] = $key;
                }
            }
        }

        // A nested section whose host is not in the list at all would otherwise
        // be dropped from the page's own inventory. Nothing writes that today;
        // the fallback keeps a hand-edited settings row visible rather than
        // silently short.
        foreach ($keys as $key) {
            if (! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * True when the page renders in the order its template is written in.
     *
     * The whole ordering mechanism is gated on this being false. A shop that
     * has never opened Appearance → Homepage — and one that has opened it and
     * changed only the switches — emits not one extra byte: no style element,
     * no extra class, no attribute. "Unchanged means unchanged" is then a
     * property of the code rather than a claim in a test.
     */
    public function orderIsDefault(): bool
    {
        return self::isDefaultOrder($this->all());
    }

    /**
     * Taken over a list that has ALREADY been read, deliberately.
     *
     * classFor() runs once per section and all() is not cheap — it reads the
     * saved payload, merges the registry over it, sorts and settles. The one
     * call it already makes answers this too, so ordering costs the page no
     * extra read at all.
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    private static function isDefaultOrder(array $rows): bool
    {
        return array_keys($rows) === array_keys(self::registry());
    }

    /**
     * The <style> element that applies the saved order, or '' for a default one.
     *
     * ── WHY INLINE CSS AND NOT resources/css ────────────────────────────────
     *
     * The storefront serves BUILT css: `@vite()` resolves to a hashed file
     * under a web root that is a different directory from the application, and
     * building it is a manual step nobody runs during an update. A rule added
     * to kbb.css therefore ships INERT until somebody rebuilds the bundle, and
     * an ordering feature that silently does nothing is what this change exists
     * to remove. Emitted here it is part of the page and cannot be stale.
     *
     * It is also the reason `.kbb-home{display:flex}` is safe. That class is on
     * four templates — home, collection, page and wishlist — and a stylesheet
     * rule would turn all four into flex containers to serve one. This element
     * is pushed by store/home.blade.php alone, so nothing else on the shop can
     * be reached by it.
     *
     * Only the fifteen movable sections get a rule. `delivery` and `ticker` are
     * not children of `.kbb-home`, so `order` on them would be a declaration
     * the browser accepts and ignores — see NESTED.
     */
    public function orderStyle(): string
    {
        $all = $this->all();

        if (self::isDefaultOrder($all)) {
            return '';
        }

        $rules = '';

        foreach ($all as $key => $row) {
            if (isset(self::NESTED[$key])) {
                continue;
            }

            $rules .= '.kbb-home>.kbb-ord-' . $row['order'] . '{order:' . $row['order'] . '}';
        }

        return '<style>.kbb-home{display:flex;flex-direction:column}' . $rules . '</style>';
    }

    /**
     * The sections a shopper meets FIRST under the banner, on a laptop and on
     * a phone -- the ones whose top row is in the first viewport.     (Lane LZ)
     *
     * The page orders its sections with CSS `order`, so source order says
     * nothing; this walks the same sorted all() the order classes come from.
     * The strips, the banner and the hero band are skipped (they are the top of
     * the page, with their own preloaded picture), and so are the category
     * circles, which carry no picture and are one short row. Read once per page.
     *
     * @return list<string>
     */
    public function firstOnScreen(): array
    {
        $skip = ['topstrip', 'cards_banner', 'countries', 'hero', 'delivery', 'ticker', 'categories'];
        $first = [];

        foreach (['desktop', 'mobile'] as $device) {
            foreach ($this->all() as $key => $row) {
                if ($row[$device] && ! in_array($key, $skip, true)) {
                    $first[$key] = true;
                    break;
                }
            }
        }

        return array_keys($first);
    }

    /** True when the section is off on both, so it need not render at all. */
    public function hidden(string $key): bool
    {
        $s = $this->all()[$key] ?? null;

        return $s !== null && ! $s['desktop'] && ! $s['mobile'];
    }

    /**
     * True when the WRAPPER a section is drawn in need not render at all.
     *
     * For fifteen of the seventeen this is hidden() itself. For a HOST it is
     * not: the hero's `<section>` is also the element the delivery strip and
     * the promo ticker are drawn inside, so it has to survive the hero being
     * switched off on both devices whenever either of those two is still on.
     * Dropping it would take two sections the owner has switched ON off the
     * page with it — which is what this file did until Lane FW.
     */
    public function bandHidden(string $key): bool
    {
        [$desktop, $mobile] = $this->bandVisibility($key);

        return ! $desktop && ! $mobile;
    }

    /**
     * The visibility of a host's wrapper: the UNION of its own and every
     * section drawn inside it.
     *
     * ── WHY A UNION AND NOT THE HOST'S OWN ──────────────────────────────────
     *
     * `.d-off{display:none !important}` is applied to the wrapper, and
     * `display:none` takes the subtree with it. A nested section's own `d-off`
     * can therefore only ever SUBTRACT from what its host shows; it can never
     * add. So the wrapper has to be visible on a device if ANY of the sections
     * it carries is on for that device, and each of them then subtracts its own
     * switch from that inside. Any other rule makes the nested rows' switches
     * decorative, which is what they were.
     *
     * A section with nothing nested in it returns its own two flags unchanged,
     * so this is the general case and classFor() is not a special one.
     *
     * @return array{0: bool, 1: bool}
     */
    private function bandVisibility(string $key): array
    {
        $all = $this->all();
        $s = $all[$key] ?? null;

        if ($s === null) {
            return [false, false];
        }

        $desktop = (bool) $s['desktop'];
        $mobile = (bool) $s['mobile'];

        foreach (self::NESTED as $child => $host) {
            if ($host !== $key || ! isset($all[$child])) {
                continue;
            }

            $desktop = $desktop || (bool) $all[$child]['desktop'];
            $mobile = $mobile || (bool) $all[$child]['mobile'];
        }

        return [$desktop, $mobile];
    }

    /**
     * The visibility class for a section wrapper.
     * d-off hides it above the mobile breakpoint, m-off at or below it.
     */
    public function classFor(string $key): string
    {
        $all = $this->all();
        $s = $all[$key] ?? null;

        if ($s === null) {
            return '';
        }

        return $this->frameClass($key, $all, (bool) $s['desktop'], (bool) $s['mobile']);
    }

    /**
     * The class for a HOST's wrapper — the hero's `<section>`.
     *
     * Same order class and same divider mark as classFor(), and the union
     * visibility instead of the host's own. For a shop with the three rows on
     * it returns exactly what classFor() returns, byte for byte, which is every
     * shop that has not used those switches.
     */
    public function bandClassFor(string $key): string
    {
        $all = $this->all();

        if (! isset($all[$key])) {
            return '';
        }

        [$desktop, $mobile] = $this->bandVisibility($key);

        return $this->frameClass($key, $all, $desktop, $mobile);
    }

    /**
     * A section's OWN d-off/m-off, with no order class and no divider mark.
     *
     * For the element that carries a host's own content — the hero's slider —
     * which sits inside a wrapper that is now showing on a device for somebody
     * else's sake. Without this the hero would be dragged back on by its own
     * lodgers, which is the same defect in the other direction.
     *
     * No order class: the slider is not a child of `.kbb-home`. No divider
     * mark: the mark belongs above the wrapper, and a second one inside it
     * would draw the separator twice.
     */
    public function deviceClassFor(string $key): string
    {
        $s = $this->all()[$key] ?? null;

        if ($s === null) {
            return '';
        }

        return trim(($s['desktop'] ? '' : 'd-off ') . ($s['mobile'] ? '' : 'm-off '));
    }

    /**
     * @param  array<string, array<string, mixed>>  $all
     */
    /**
     * The sections whose <section> can be absent from the document entirely,
     * and are therefore ASSUMED ABSENT until the page says otherwise.
     *                                                                (Lane SEC)
     *
     * One key, and it is not a list waiting to grow: every other section in the
     * registry writes its element unconditionally and hides it with a class, so
     * "is it in the document" is a question only this one can answer
     * differently. `cards_banner`'s element lives inside its own @if — a shop
     * with no banner picture has no element for it at all — and it is now the
     * FIRST key in the registry, which is what makes the distinction matter.
     *
     * @var list<string>
     */
    public const MAY_BE_ABSENT = ['cards_banner'];

    /**
     * The keys of MAY_BE_ABSENT this request has confirmed ARE in the document.
     *
     * @var array<string, true>
     */
    private array $present = [];

    /**
     * Tell this instance a section that may be absent is in fact being drawn.
     *
     * ── WHY THE DEFAULT IS "ABSENT" AND NOT "PRESENT" ───────────────────────
     *
     * Two reasons, and the second one is the whole of why it is written this
     * way round.
     *
     * 1. It is what is true of the shop. `cards_banner` draws only when a
     *    published set carries a published card with a picture, which is not
     *    the case on any shop that has not uploaded one.
     *
     * 2. A READER THAT DOES NOT KNOW ABOUT THIS CALL GETS THE OLD ANSWER, and
     *    there is such a reader on every run of StorefrontEnglishUnchangedTest.
     *    That walk renders the views as they stood at BASE_COMMIT against the
     *    PHP in the WORKING TREE — so the "before" page is old Blade calling
     *    new PHP. Written the other way round (the page declaring absence) the
     *    old Blade declared nothing, the banner counted as present, and the
     *    hero gained a divider mark on the BEFORE side only: a red diff that is
     *    an artefact of the harness rather than a changed page. Measured, byte
     *    21488: `<section class="sec dv"` before against `<section class="sec "`
     *    after. Written this way the old Blade gets exactly the page it always
     *    rendered.
     *
     * Idempotent, and scoped to this instance — the shop and the admin preview
     * each build their own.
     */
    public function draws(string $key): void
    {
        $this->present[$key] = true;
    }

    /**
     * The first key in the effective order whose section will be in the page.
     *
     * @param array<string, array<string, mixed>> $all
     */
    private function firstDrawnKey(array $all): ?string
    {
        foreach ($all as $key => $_) {
            // Lane HC: a strip is not "the first section" — the hero under it
            // keeps exactly the divider it had before the strips existed.
            if (in_array($key, self::STRIPS, true)) {
                continue;
            }

            if (! in_array($key, self::MAY_BE_ABSENT, true) || isset($this->present[$key])) {
                return $key;
            }
        }

        return null;
    }

    private function frameClass(string $key, array $all, bool $desktop, bool $mobile): string
    {
        $s = $all[$key];

        // The divider class is added here rather than in the template: all
        // seventeen sections already call this, so none can be missed and none
        // of them had to change. The ORDER class rides the same argument, which
        // is why store/home.blade.php needed no per-section edit for it.
        $divider = app(SectionDividers::class);

        // "The first section" is the first one in the SAVED order, not the
        // first one in the registry. With the order left alone the two are the
        // same key and this renders identically; once the hero has been moved
        // down the page, `first => off` has to mean the section that is now at
        // the top, or the setting names a position rather than a section.
        //
        // ▲ AND IT IS THE FIRST ONE THAT ACTUALLY DRAWS.              (Lane SEC)
        //
        // array_key_first() alone was right for as long as the first key was
        // `hero`, which always renders its band. The first key is now
        // `cards_banner`, which is the one section in this registry that can be
        // absent from the document entirely — its <section> is inside its @if,
        // so a shop with no banner picture has no element for it at all.
        //
        // THE BUG THAT CAUGHT, MEASURED: with dividers at their shipped values
        // (`style` ticks, `scope` all, `first` off) every section but the first
        // carries `dv`. With `cards_banner` first and ABSENT, the hero became
        // "not first" and gained a tick mark above it — a rule directly under
        // the header on every phone homepage of every shop that has not
        // uploaded a banner picture, which is a changed page nobody asked for.
        // HomepageHeroBandVisibilityTest reported it as `class="sec dv"` where
        // it had pinned `class="sec "`.
        //
        // StorefrontEnglishUnchangedTest did NOT report it, which is worth the
        // line: its database leaves the dividers screen alone and its seed puts
        // the marks off, so the walk rendered `dv` on neither side. Two blind
        // spots in one round — this and approvedInsertions() cutting the
        // countries strip — and both were found by a narrower test.
        $mark = in_array($key, self::STRIPS, true) || ($key === $this->firstDrawnKey($all) && ! $divider->showAboveFirst())
            ? ''
            : $divider->classFor($key);

        // Nothing is emitted while the order is the template's own, so a shop
        // that has not touched the screen renders the same bytes it did before
        // this feature existed. `delivery` and `ticker` never get one: they are
        // not children of `.kbb-home` and the declaration would be inert on
        // them — see NESTED.
        $ord = ! isset(self::NESTED[$key]) && ! self::isDefaultOrder($all)
            ? 'kbb-ord-' . $s['order'] . ' '
            : '';

        /*
         * ── THE PANEL AND THE WIDTH, AND ONLY WHEN THEY ARE NOT THE CSS
         *    BASELINE ──────────────────────────────────────────────── Lane BG
         *
         * Same argument the divider and order classes above ride: all nineteen
         * sections already call this method, so store/home.blade.php needed no
         * per-section edit for either control and no section can be missed.
         *
         * WHICH VALUE IS SILENT IS A DECISION, NOT AN ACCIDENT. The stylesheet
         * now draws no panel and the page's own width by DEFAULT, so `off` and
         * `normal` emit nothing at all — which means the eighteen sections the
         * owner has not touched carry exactly the class attribute they carried
         * before this feature existed, to the byte. Only a section he has
         * changed grows a class, and the one this release changes for him is
         * the banner.
         *
         * Nothing here can print an operator's string: both values come back
         * from castRow(), which is ModuleSchema::cast() under
         * SECTION_POLICY's `invalid => default`, so a value that is not a key
         * of BACKGROUNDS / WIDTHS is replaced by the default before it reaches
         * this line. Rule 5 — "a select stores one of its own options or the
         * default" — and the class name is built from a token this file
         * declares rather than from anything a settings row holds.
         *
         * NULL ON A NESTED ROW, which is castRow() saying the control does not
         * apply; the `?? ` arms are what make that mean "emit nothing" rather
         * than "emit kbb-secw-".
         */
        $panel = ($s['background'] ?? 'off') === 'panel' ? 'kbb-secbg-on ' : '';
        $width = ($s['width'] ?? 'normal') === 'normal' ? '' : 'kbb-secw-' . $s['width'] . ' ';

        $class = trim($ord . $panel . $width . ($desktop ? '' : 'd-off ') . ($mobile ? '' : 'm-off ') . $mark);

        // Lane FS: the Fonts & size tab's hook, LAST and only on a section with
        // a moved value — SectionType::classFor() answers '' otherwise, so an
        // untouched shop's class strings are the bytes they were.
        $ty = \App\Support\SectionType::classFor($key, $this->typeMap());
        $class = $ty === '' ? $class : trim($class . ' ' . $ty);

        /*
         * THE SELECTION HOOK, AND ONLY ON AN ANNOTATING PROPOSAL (Lane HL).
         *
         * It is appended here rather than in the template because all
         * nineteen sections already call this — the same argument the divider
         * and order classes above are added on — so no section can be missed
         * and store/home.blade.php needed no edit for it.
         *
         * BYTE-NEUTRAL EVERYWHERE ELSE, which is the point: $annotate is false
         * on every reader but the one /admin-api/homepage/live builds, so the
         * shop and the byte-identical preview return exactly the string this
         * method returned before the hook existed.
         */
        return $this->annotate
            ? trim($class . ' ' . self::SELECT_CLASS . ' ' . self::SELECT_CLASS . '-' . $key)
            : $class;
    }

    /** @var array<string, array<string, string|int>>|null */
    private ?array $type = null;

    /**
     * The Fonts & size map (Lane FS), read once per reader from the settings
     * map the page has already loaded — no query of its own.
     *
     * @return array<string, array<string, string|int>>
     */
    public function typeMap(): array
    {
        return $this->type ??= \App\Support\SectionType::read($this->settings->all());
    }

    public function skinFor(string $key): ?string
    {
        return $this->all()[$key]['skin'] ?? null;
    }

    /**
     * The fields one section's row is cast through.
     *
     * Memoised by the DEFAULT SKIN rather than by the section, because that is
     * the only thing that differs between them: seventeen sections share five
     * field sets. The key is this class plus that default, so it cannot collide
     * with another module's entry in the shared memo.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fieldsFor(string $key): array
    {
        $defaultSkin = (string) (self::registry()[$key][3] ?? '');

        /*
         * ▲ THE MEMO KEY CARRIES THE WIDTH DEFAULT TOO, AND IT HAS TO.
         *                                                          (Lane BG)
         *
         * ModuleSchema::normalised() is `self::$normalised[$key] ??= …`, a
         * process-level memo. The key was the skin default alone, which was
         * right while the skin was the only thing overridesFor() varied by
         * section. `cards_banner` now overrides the WIDTH default as well and
         * has no skin — so its key would have been `HomepageSections:` , the
         * same string nineteen other sections without a skin produce, and
         * whichever of them was read FIRST in the process would have decided
         * the width default for all of them.
         *
         * The failure is order-dependent and silent: read the banner first and
         * every section on the page goes full-bleed; read `hero` first and the
         * banner never leaves its card. Both are one cached array away from the
         * other, and neither errors.
         */
        return ModuleSchema::normalised(
            self::class.':'.$defaultSkin.':'.self::widthDefault($key).(in_array($key, self::OFF_BY_DEFAULT, true) ? ':off' : '').(in_array($key, self::MOBILE_ONLY_BY_DEFAULT, true) ? ':mob' : ''),
            self::SECTION_SCHEMA,
            self::SECTION_POLICY,
            self::overridesFor($key),
        );
    }

    /**
     * What the schema cannot say about this section on its own.
     *
     * ONE DEFINITION, TWO CONSUMERS — the cast above and the RENDER below.
     * The option set lives in another registry, which is what ModuleSchema's
     * `overrides` channel is for, and the default is the section's own. Written
     * out twice it would be the shape docs/M-PHASE3-SETTINGS-SCHEMA.md §1
     * measured: the picker offering a skin the cast refuses, or the cast
     * falling back to a default the picker does not show.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function overridesFor(string $key): array
    {
        return [
            'skin' => [
                'options' => GridSkins::ALL,
                'default' => (string) (self::registry()[$key][3] ?? ''),
            ],
            // The banner's `bleed`, and `normal` for every other section.
            'width' => ['default' => self::widthDefault($key)],
        ] + (in_array($key, self::OFF_BY_DEFAULT, true)
            // Row 55: off on both devices until the owner switches it back.
            ? ['desktop' => ['default' => false], 'mobile' => ['default' => false]]
            : (in_array($key, self::MOBILE_ONLY_BY_DEFAULT, true)
                ? ['desktop' => ['default' => false], 'mobile' => ['default' => true]]
                : []));
    }

    /**
     * The width a section ships at, before anybody has chosen one.
     *
     * One line, and it exists so that the memo key in fieldsFor() and the
     * override in overridesFor() cannot come to disagree — which is the exact
     * shape of the defect the comment in fieldsFor() describes. Two literals in
     * two methods is how that happens; one method is how it cannot.
     */
    private static function widthDefault(string $key): string
    {
        return self::WIDTH_DEFAULTS[$key] ?? 'normal';
    }

    /**
     * One section's controls, as ModuleSchema::tabs() emits them (Lane HL).
     *
     * ── WHY THIS IS HERE AND NOT IN THE CONTROLLER ──────────────────────────
     *
     * The live editor draws a section's controls from the same three constants
     * the shop casts them through — SECTION_SCHEMA, SECTION_TABS,
     * SECTION_POLICY — and the same overrides. Building that call anywhere else
     * would be a second description of these controls in the one project that
     * has already paid for four copies of three rules; building it here means
     * a field added to SECTION_SCHEMA appears on the screen, is cast on the way
     * in, and is drawn from one statement of what it is.
     *
     * THE GRID CONTROL IS DROPPED FOR A SECTION WITH NO GRID, which is
     * castRow()'s rule restated on the render side: such a section stores
     * `skin => null`, so a picker for it would be a control whose value is
     * discarded on the way in — a box with no writer behind it, which is the
     * shape AdminConsoleWriteTokenTest was written after.
     *
     * @param  array<string, mixed>  $row  a row as all() answers it
     * @return list<array<string, mixed>>
     */
    public static function sectionTabs(string $key, array $row): array
    {
        $schema = self::SECTION_SCHEMA;

        if (! (self::registry()[$key][2] ?? false)) {
            unset($schema['skin']);
        }

        // See castRow(): the two rows drawn inside the hero have no wrapper of
        // their own, so neither rule can reach them and neither control is
        // offered. ModuleSchema::tabs() drops a group whose fields are all
        // gone, so the whole "How it looks" heading disappears with them.
        if (isset(self::NESTED[$key])) {
            unset($schema['background'], $schema['width']);
        }

        return ModuleSchema::tabs(
            $schema,
            self::SECTION_TABS,
            $row,
            self::SECTION_POLICY,
            self::overridesFor($key),
        );
    }

    /**
     * One row, re-derived: the single boundary all(), save() and the preview
     * share.
     *
     * A section with no product grid stores `skin => null` — that is the shape
     * this screen has always written and the console draws no picker for it, so
     * the cast's answer is discarded rather than stored. Nulling it here rather
     * than declaring a second schema keeps one schema for seventeen rows.
     *
     * @param  array<string, mixed>  $row
     * @return array{desktop: bool, mobile: bool, skin: string|null}
     */
    private static function castRow(string $key, array $row): array
    {
        $fields = self::fieldsFor($key);
        $out = [];

        foreach ($fields as $name => $field) {
            /*
             * `??` AND NOT array_key_exists(), AND THE CORPUS IS WHY.
             *
             * Both readers this replaces wrote `$row['desktop'] ?? true`, which
             * treats a row whose value IS NULL exactly like a row that has no
             * such key — so a stored null has always meant "shown". The obvious
             * migration, array_key_exists() plus the field default, hands
             * cast() a literal null instead and `(bool) null` is FALSE: every
             * section carrying a null would have gone dark on a shop that had
             * them on. Caught by HomepageSectionSchemaTest's corpus, which is
             * the only reason it is written this way rather than the other.
             */
            $out[$name] = ModuleSchema::cast($field, $row[$name] ?? $field['default']);
        }

        return [
            'desktop' => (bool) $out['desktop'],
            'mobile' => (bool) $out['mobile'],
            'skin' => (self::registry()[$key][2] ?? false) ? (string) $out['skin'] : null,
            /*
             * NULL FOR A NESTED ROW, the same way `skin` is null for a section
             * with no grid, and for the same reason: the value would be a
             * setting that is stored, shown and never read. `delivery` and
             * `ticker` are drawn inside the hero's `<section>` as plain divs —
             * there is no `.wrap` of their own for either rule to reach — so
             * the honest answer on those two rows is "this does not apply",
             * and sectionTabs() drops the controls to match.
             */
            'background' => isset(self::NESTED[$key]) ? null : (string) $out['background'],
            'width' => isset(self::NESTED[$key]) ? null : (string) $out['width'],
        ];
    }

    /**
     * Persist a validated payload.
     *
     * REFUSES OUTRIGHT ON A PROPOSAL INSTANCE. proposing() exists so that a
     * configuration can be RENDERED without being stored; an instance carrying
     * one that could also write would be a preview that publishes, which is the
     * one failure this feature must not have. It throws rather than returning
     * quietly, because a silent no-op here looks to the caller exactly like a
     * successful save.
     */
    public function save(array $sections): void
    {
        if ($this->proposed !== null) {
            throw new \LogicException('A homepage preview reader may not write. See HomepageSections::proposing().');
        }

        $clean = [];
        $order = 0;

        foreach ($sections as $key => $row) {
            if (! isset(self::registry()[$key])) {
                continue;
            }

            $cast = self::castRow($key, is_array($row) ? $row : []);

            // The key order is the one this screen has always stored. Nothing
            // reads the blob positionally, but a settings row that rewrites
            // itself on every save is a diff nobody can review.
            $clean[$key] = [
                'desktop' => $cast['desktop'],
                'mobile' => $cast['mobile'],
                'order' => (int) (is_array($row) ? ($row['order'] ?? $order) : $order),
                'skin' => $cast['skin'],
                // Lane BG. Stored through the same castRow() the other three
                // go through, so what is written is a key of BACKGROUNDS /
                // WIDTHS or the section's own default and never an operator's
                // string — and a nested row stores null, which is castRow()
                // saying the control does not apply to it.
                'background' => $cast['background'],
                'width' => $cast['width'],
            ];

            $order++;
        }

        $this->settings->set('homepage_sections', $clean);
    }
}
