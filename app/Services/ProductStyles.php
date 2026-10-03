<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GridSkins;

/**
 * How product cards look, everywhere they appear.
 *
 * Gathered onto one screen because these settings were previously spread
 * between the homepage screen and the ecommerce panel, which made it hard to
 * see what a change would affect.
 */
class ProductStyles
{
    public const SCHEMA = [
        // ── Layout ──
        // ▲ `showcase`, NOT `classic` — the default the owner asked for in as
        // many words ("keep this design by default from backend"). It is
        // GridSkins::DEFAULT rather than the literal, because this screen and
        // the resolver disagreeing about what "default" means is the defect
        // that shape of duplication produces. See that constant for the whole
        // list of places the switch lives.
        'grid_skin'          => ['skin',   'Default card style', GridSkins::DEFAULT, 'Used wherever a grid does not choose its own.'],
        /*
         * ── FIVE CONTROLS ARE GONE FROM HERE, AND WHY EACH ONE ────── Lane AD ──
         *
         * Measured, not reasoned: every key in this schema was moved off its
         * default and the whole storefront re-rendered, and twenty of them
         * changed no byte of it. ProductStylesReachTheShopTest is that
         * measurement, kept as a test. The other fifteen are wired up in
         * layouts/store.blade.php now. These five are not, because each is a
         * SECOND ANSWER to a question something else already answers, and a
         * screen that offers two answers to one question is the thing this shop
         * keeps paying for.
         *
         *   Columns · desktop   grid_columns. The count comes from --kbb-track
         *                       in kbb.css, derived from the row the grid really
         *                       has, and from Appearance → Site layout's tile
         *                       minimum — which reaches all five product grids
         *                       where this reached one. It is ALSO still offered
         *                       on two other screens (the Ecommerce panel's
         *                       Catalogue layout, and LayoutApiController's skin
         *                       + columns save), so it was dead in triplicate.
         *                       The key is left in the settings table because
         *                       those two still write it; it is this screen that
         *                       had no business offering it.
         *   Columns · tablet    grid_columns_tablet. Never reached anything at
         *                       all, on any grid, in any release — --kbb-cols-t
         *                       had exactly one writer and that writer was only
         *                       ever called from the admin preview. There is no
         *                       tablet ladder to wire it to any more: the count
         *                       rises with the row.
         *   Columns · phone     grid_columns_mobile. --kbb-cols-floor:2 in
         *                       kbb.css guarantees two on a phone from the
         *                       grid's own width, which is the same answer
         *                       arrived at from the thing that matters.
         *   Gap between cards   grid_gap. THE GAP IS NOT ONE NUMBER ON THIS SHOP
         *                       AND DELIBERATELY SO: /shop sits beside a 250px
         *                       filter rail and declares 18px (12px under 680),
         *                       the related-products rail declares 18px (10px
         *                       under 600), and the shared rails use 16px. Those
         *                       are three documented decisions about three
         *                       different row widths, each made on its own
         *                       element, and an inherited custom property cannot
         *                       reach past any of them. A slider that silently
         *                       governed three grids of five would be the same
         *                       inconsistency again. It is worse than that:
         *                       --kbb-gap is a TERM IN THE TRACK ARITHMETIC
         *                       (kbb.css: `(100% - (floor - 1) * var(--kbb-gap))
         *                       / floor`), so "Gap between cards" would also
         *                       silently change the COLUMN COUNT — the question
         *                       Appearance → Site layout now owns. If a gap
         *                       control is ever wanted it belongs there, beside
         *                       the tile minimum, as one number for all five.
         *   Button wording      cart_label, below. The card's button text is
         *                       __('store.product_card.add_to_cart') and has
         *                       been for as long as the tile has been shared;
         *                       Content → Translations is where it is edited.
         */
        'card_radius'        => ['range',  'Card roundness', 14, '', ['min' => 0, 'max' => 26, 'step' => 2, 'unit' => 'px']],
        // ▲ SQUARE, NOT PORTRAIT — a default the owner asked for in as many
        // words: "i need the same, with square image thumbnail". Lane PG. The
        // fallback in the match() below moved with it, so the sheet and this
        // screen cannot disagree about what "default" means.
        'image_ratio'        => ['select', 'Image shape', 'square', '', ['square' => 'Square', 'portrait' => 'Portrait', 'tall' => 'Tall', 'landscape' => 'Landscape']],

        // ── What the card shows ──
        /*
         * ▲ TWO DEFAULTS MOVED FROM true TO false, AND THE OWNER ASKED FOR BOTH
         *   IN AS MANY WORDS.                                      (Lane CARD)
         *
         * "i like this option, but i want to hide the brand name, category name
         *  by default. only name, rating (if any), pricing and cart buttons"
         *
         * CLAUDE.md rule 1, as of 30 September: what he asked for is the shop's
         * new state rather than a switch he has to go and find. The control is
         * built anyway — he may want the line back, and a change with no way
         * back is worse than no change — and the moved default is named in the
         * commit rather than buried.
         *
         * THEY ARE NOT NEW CONTROLS. "Brand name" and "Category label" have sat
         * on this screen since Lane AD wired it up, and adding a second pair
         * somewhere else would be the "two answers to one question" the note
         * above calls the thing this shop keeps paying for. What moved is the
         * shipped value and — because the `.pc-no*` stylesheet rules that
         * carried them reach only three pages of the shop — WHERE the decision
         * is taken: components/product-card.blade.php reads these three keys and
         * omits the markup. Its header carries that measurement.
         *
         * The migration beside this change deletes any stored row for the two,
         * because a stored row is what stops a new default from being seen and
         * a shop that has ever saved this screen has one at the old value.
         *
         * The other five did not move. The owner asked about the brand line and
         * the category eyebrow, and rule 1's other half — everything he did not
         * ask about ships byte-identical — is untouched.
         */
        'show_brand'         => ['bool',   'Brand name', false, 'Off: the owner asked the card to show the name, the rating, the price and the button.'],
        'show_category'      => ['bool',   'Category label', false, 'Off, with Brand name, and for the same reason.'],
        'show_rating'        => ['bool',   'Stars and review count', true, ''],
        'show_was_price'     => ['bool',   'Was price', true, 'The struck-through original.'],
        /*
         * ▲ AND TWO MORE MOVED FROM true TO false, ALSO ASKED FOR IN AS MANY
         *   WORDS.                                                   (Lane PR)
         *
         * "Turn off by default on the product grid card, new and discount tag."
         *
         * Same shape as the pair above, for the same measured reason: the
         * `.pc-nodisc` / `.pc-nonew` rules live only in kbb-grid-skins.css,
         * which three pages load, so these two switches never reached /shop,
         * a category or a brand page. components/product-card.blade.php reads
         * both keys now and omits the pill, and bodyClass() below no longer
         * emits the two classes — `.pc-nonew .kbb-badge-new` would also have
         * hidden the bestsellers rail's `#1`, `#2` rank pills, which wear the
         * same class and are not a NEW tag.
         *
         * 2027_07_11_000000_clear_caches_lane_pr_grid deletes any stored row
         * for these two, as Lane CARD's migration did for its pair.
         */
        'show_discount'      => ['bool',   'Discount badge', false, 'The -N% pill on the photograph. Off by default, as you asked; the struck-through was-price still shows a markdown.'],
        'show_new'           => ['bool',   'New badge', false, 'The NEW pill on products added in the last 30 days. Off by default, as you asked.'],
        'show_cart'          => ['bool',   'Add to cart button', true, ''],
        'name_lines'         => ['range',  'Product name lines', 0, 'Zero shows the whole name, however long. One to four trims it.', ['min' => 0, 'max' => 4, 'step' => 1, 'unit' => '']],

        /*
         * ── HOVER ON A PHONE ───────────────────────────────────── Lane PR ──
         *
         * "Remove grid hover from products in mobile ... I want to turn off by
         * default on mobile devices only." A phone has no pointer to rest on a
         * card, so `:hover` there is the state a TAP leaves behind: the card
         * keeps its lifted shadow and its zoomed photograph after the finger
         * has gone, and it stays that way while the shopper scrolls on.
         * Measured on /collections/{slug}/ at 390 with touch emulation before
         * this: box-shadow `none` → `0 14px 34px rgba(42,34,40,.12)` and the
         * photograph `none` → `scale(1.06)` on a tap.
         *
         * OFF means the rule block in kbb.css ("CARD HOVER ON A PHONE") applies
         * on `(hover:none)` and below 701px; ON puts `pc-phonehover` on <body>
         * and the block steps aside. A desktop with a mouse is untouched either
         * way. Ships OFF because he asked for it in as many words.
         */
        'hover_phone'        => ['bool',   'Card hover effects on phones', false, 'Off: on a phone or tablet a tapped card does not lift, shadow or zoom its photo. A desktop with a mouse keeps its hover either way.'],

        /*
         * ── SPACING AND TYPE INSIDE THE CARD ────────────────────── Lane PR ──
         *
         * "I need the full control of grid card spacing like between image,
         * title, pricing row, add to cart, and also control of font bold etc."
         *
         * Phone (`_m`, up to 700px — where the card itself already changes, see
         * the heart and the button size in kbb.css) and desktop (`_d`, 701px
         * up). Weights are one value for both: a weight that differs by screen
         * size reads as a bug rather than a design.
         *
         * EVERY DEFAULT IS THE VALUE THE SHOP RENDERS TODAY, measured in
         * Chromium on the shipped Showcase card (docs/lane-pr-shots/before):
         * text-column padding 16, photo → name 16, the price row's own space
         * above it 12, price row → button 12, name 14px/600, price 13.5px on a
         * phone and 15px on a desktop, sale price 700, button 11px on a phone
         * and 12px on a desktop, brand 10.5px/600.
         *
         * AND AT THOSE DEFAULTS NOTHING IS EMITTED: cardCss() returns '' until
         * a value moves, and then emits ONLY the values that moved. That is
         * what keeps the shop byte-identical when this ships, and what lets the
         * other card designs keep their own spacing for every control the owner
         * has not touched — the numbers above are the Showcase card's, not a
         * value forced onto all of them.
         *
         * Sizes are selects, not sliders, because three of today's values are
         * half pixels (10.5, 13.5) and a `range` here stores whole numbers.
         */
        'card_pad_m'         => ['range',  'Inner padding · phone', 16, 'The space inside the card around the text, sides and bottom.', ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'card_pad_d'         => ['range',  'Inner padding · desktop', 16, '', ['min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px']],
        'card_gap_img_m'     => ['range',  'Photo → name · phone', 16, 'The space between the photograph and the first line of text.', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'card_gap_img_d'     => ['range',  'Photo → name · desktop', 16, '', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        // Brand → name and name → stars: 2.60.371. The owner, 3 October: "spacing between image, title, pricing,
        // rating add to cart". The defaults are the Showcase card's own --sc-brand-mb (3px) and --sc-rate-mt (8px).
        'card_gap_brand_m'   => ['range',  'Brand → name · phone', 3, 'The space under the brand line. Only shows when Card content → Brand name is on.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'card_gap_brand_d'   => ['range',  'Brand → name · desktop', 3, '', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'card_gap_rate_m'    => ['range',  'Name → stars · phone', 8, 'The space above the stars, on products that have reviews.', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'card_gap_rate_d'    => ['range',  'Name → stars · desktop', 8, '', ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px']],
        'card_gap_price_m'   => ['range',  'Name → price row · phone', 12, 'Extra space above the price. The stars, when a product has reviews, sit in the room above this.', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'card_gap_price_d'   => ['range',  'Name → price row · desktop', 12, '', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'card_gap_cart_m'    => ['range',  'Price row → Add to cart · phone', 12, '', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'card_gap_cart_d'    => ['range',  'Price row → Add to cart · desktop', 12, '', ['min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'card_fs_title_m'    => ['select', 'Product name size · phone', '14px', '', self::FONT_SIZES],
        'card_fs_title_d'    => ['select', 'Product name size · desktop', '14px', '', self::FONT_SIZES],
        'card_fs_price_m'    => ['select', 'Price size · phone', '13.5px', 'The price and the sale price.', self::FONT_SIZES],
        'card_fs_price_d'    => ['select', 'Price size · desktop', '15px', '', self::FONT_SIZES],
        'card_fs_btn_m'      => ['select', 'Button text size · phone', '11px', '', self::FONT_SIZES],
        'card_fs_btn_d'      => ['select', 'Button text size · desktop', '12px', '', self::FONT_SIZES],
        'card_fs_brand_m'    => ['select', 'Brand name size · phone', '10.5px', 'Only shows when Card content → Brand name is on.', self::FONT_SIZES],
        'card_fs_brand_d'    => ['select', 'Brand name size · desktop', '10.5px', '', self::FONT_SIZES],
        'card_fw_title'      => ['select', 'Product name weight', '600', '', self::FONT_WEIGHTS],
        'card_fw_price'      => ['select', 'Price weight', '700', 'A product at one price.', self::FONT_WEIGHTS],
        'card_fw_sale'       => ['select', 'Sale price weight', '700', 'The marked-down price beside a struck-through one.', self::FONT_WEIGHTS],
        'card_fw_btn'        => ['select', 'Button text weight', '700', '', self::FONT_WEIGHTS],
        'card_fw_brand'      => ['select', 'Brand name weight', '600', '', self::FONT_WEIGHTS],

        // ── Colour ──
        'sale_colour'        => ['colour', 'Sale badge', '#E23B57', ''],
        'new_colour'         => ['colour', 'New badge', '#1F9D55', ''],
        'price_colour'       => ['colour', 'Price', '#2A2228', ''],
        'star_colour'        => ['colour', 'Stars', '#E8A33D', ''],
        'cart_bg'            => ['colour', 'Button background', '#E0567B', ''],
        'cart_fg'            => ['colour', 'Button text', '#FFFFFF', ''],

        // ── Sticky add to cart ──
        // Off by default: this bar was absent from the product page for several
        // releases, so switching it on for every shop at once would be a visible
        // change nobody asked for.
        'sticky_show'        => ['bool',   'Show the sticky bar', false, 'A bar with the price and Add to cart, once the main button scrolls away.'],
        'sticky_devices'     => ['select', 'Show on', 'phone', '', ['phone' => 'Phone only', 'phone_tablet' => 'Phone and tablet', 'all' => 'Every screen']],
        'sticky_trigger'     => ['select', 'Appears', 'button', '', ['button' => 'When the Add button scrolls away', 'offset' => 'After a set distance']],
        'sticky_offset'      => ['range',  'Distance before it appears', 200, 'Used only when the trigger is a set distance.', ['min' => 0, 'max' => 900, 'step' => 20, 'unit' => 'px']],
        'sticky_thumb'       => ['bool',   'Show the thumbnail', true, ''],
        'sticky_name'        => ['bool',   'Show the product name', true, ''],
        'sticky_price'       => ['bool',   'Show the price', true, ''],
        'sticky_label'       => ['text',   'Button wording', 'Add to cart', ''],
        'sticky_bg'          => ['colour', 'Bar background', '#FFFFFF', ''],
        'sticky_btn_bg'      => ['colour', 'Button background', '#2A2228', ''],
        'sticky_btn_fg'      => ['colour', 'Button text', '#FFFFFF', ''],
        'sticky_radius'      => ['range',  'Button roundness', 99, '', ['min' => 0, 'max' => 99, 'step' => 3, 'unit' => 'px']],
    ];

    /**
     * The sizes a "size" select offers, 8px to 24px. A select stores one of
     * its own options or the default (rule 5), so the value that reaches
     * cardCss() is always one of these keys, unit included.
     *
     * THE KEYS CARRY THEIR UNIT ON PURPOSE. '14' is an integer-like key, and
     * a browser orders an object's integer-like keys ahead of all the others,
     * so the console's dropdown read 8, 9, 10 … 24, 8.5, 9.5 … — every half
     * size at the bottom. '14px' is a string key and keeps this order.
     */
    public const FONT_SIZES = [
        '8px' => '8px', '8.5px' => '8.5px', '9px' => '9px', '9.5px' => '9.5px', '10px' => '10px',
        '10.5px' => '10.5px', '11px' => '11px', '11.5px' => '11.5px', '12px' => '12px', '12.5px' => '12.5px',
        '13px' => '13px', '13.5px' => '13.5px', '14px' => '14px', '14.5px' => '14.5px', '15px' => '15px',
        '15.5px' => '15.5px', '16px' => '16px', '17px' => '17px', '18px' => '18px', '19px' => '19px',
        '20px' => '20px', '22px' => '22px', '24px' => '24px',
    ];

    public const FONT_WEIGHTS = [
        '400' => 'Regular (400)', '500' => 'Medium (500)', '600' => 'Semi-bold (600)', '700' => 'Bold (700)',
    ];

    public const TABS = [
        'layout'  => ['Layout', 'Card shape, corners, and hover on a phone.',
                      ['grid_skin', 'card_radius', 'image_ratio', 'hover_phone']],
        'content' => ['Card content', 'What each card shows.',
                      ['show_brand', 'show_category', 'show_rating', 'show_was_price', 'show_discount', 'show_new', 'show_cart', 'name_lines']],
        'spacing' => ['Spacing & type', 'The space between the photo, the name, the price and the button, and the size and weight of each — a phone and a desktop set apart. Every value starts at what the shop shows today, so nothing moves until you move it.',
                      ['card_pad_m', 'card_pad_d', 'card_gap_img_m', 'card_gap_img_d',
                       'card_gap_brand_m', 'card_gap_brand_d', 'card_gap_rate_m', 'card_gap_rate_d',
                       'card_gap_price_m', 'card_gap_price_d', 'card_gap_cart_m', 'card_gap_cart_d',
                       'card_fs_title_m', 'card_fs_title_d', 'card_fs_price_m', 'card_fs_price_d',
                       'card_fs_btn_m', 'card_fs_btn_d', 'card_fs_brand_m', 'card_fs_brand_d',
                       'card_fw_title', 'card_fw_price', 'card_fw_sale', 'card_fw_btn', 'card_fw_brand']],
        'colour'  => ['Colour', 'Badges, price and the button.',
                      ['sale_colour', 'new_colour', 'price_colour', 'star_colour', 'cart_bg', 'cart_fg']],
        'sticky'  => ['Sticky Add to Cart', 'The bar that follows the shopper down the product page.',
                      ['sticky_show', 'sticky_devices', 'sticky_trigger', 'sticky_offset',
                       'sticky_thumb', 'sticky_name', 'sticky_price', 'sticky_label',
                       'sticky_bg', 'sticky_btn_bg', 'sticky_btn_fg', 'sticky_radius']],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * One resolved set of values per instance, and one instance per request.
     *
     * ── WHY THIS IS HERE, MEASURED RATHER THAN REASONED ───────── Lane CARD ──
     *
     * components/product-card.blade.php reads three of these keys, and it runs
     * ONCE PER TILE. Every `$this->settings->get()` below calls
     * SettingsService::all(), which is a `Cache::rememberForever` — so a
     * 24-product /shop page was doing 24 × 29 = 696 cache reads for a set of
     * values that cannot change inside one request.
     *
     * Measured on this branch's own preview, thirty sequential renders of
     * /shop, three passes each way:
     *
     *     with a fresh all() per tile   6799 / 5576 / 6315 ms
     *     with the values resolved once 3595 / 3811 / 3363 ms
     *
     * — about 87ms a page on a page that takes ~120. `StorefrontQueryBudgetTest`
     * would never have seen it: it counts QUERIES, and this costs none.
     *
     * ── scoped, NOT singleton, AND NOT A STATIC ─────────────────────────────
     *
     * AppServiceProvider binds this class `scoped`, exactly as it binds
     * CartService, SettingsService and VariantPricing, and for the reasons
     * recorded there: a singleton would survive between requests on a queue
     * worker, and a process-level static is the trap CLAUDE.md records against
     * Setting::map() — it would survive `forgetScopedInstances()`, which is how
     * both StorefrontQueryBudgetTest and every test in this repository that
     * moves a setting and re-renders gets a clean read.
     *
     * save() drops it, so a screen that writes and then reads back in the same
     * request sees what it wrote.
     */
    private ?array $resolved = null;

    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get($key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $this->resolved = $out;
    }

    /** Drop the resolved set, so the next all() reads the settings again. */
    public function forgetResolved(): void
    {
        $this->resolved = null;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** This screen's point on ModuleSchema's four policy axes. */
    public const POLICY = [
        'max' => 60,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'strict',
        'bool' => 'cast',
    ];

    /**
     * The option set the positional SCHEMA has no slot for.
     *
     * `grid_skin` is a select in everything but name: it stores one of
     * GridSkins::ALL or the default. That set lived in another class and the
     * SCHEMA never named it, so nothing checking schemas could check this
     * control. Naming it here is what puts it under rule 5 with the rest.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        return ['grid_skin' => ['options' => GridSkins::ALL]];
    }

    public function cast(string $key, mixed $value): mixed
    {
        if (! isset(self::SCHEMA[$key])) {
            return null;
        }

        return ModuleSchema::cast(
            ModuleSchema::normalise(self::SCHEMA, self::POLICY, self::overrides())[$key],
            $value,
        );
    }

    /**
     * Stored as individual settings rather than one blob, because grid_skin and
     * grid_columns are already read by name elsewhere and must keep working.
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set($key, $this->cast($key, $value));
            }
        }

        // The memo above is now a record of what this screen said BEFORE the
        // save. A screen that writes and reads back in one request would
        // otherwise be told its own change did not happen.
        $this->resolved = null;
    }

    public function cssVariables(): string
    {
        $c = $this->all();

        $ratio = match ($c['image_ratio']) {
            'square' => '1/1',
            'tall' => '1/1.25',
            'landscape' => '1.2/1',
            'portrait' => '1/1.02',
            default => '1/1',
        };

        return implode(';', [
            /*
             * --kbb-cols, --kbb-cols-t, --kbb-cols-m and --kbb-gap used to head
             * this list. They are gone with the controls that fed them; see the
             * note in SCHEMA. Emitting a column count here would now FIGHT the
             * track arithmetic in kbb.css rather than feed it.
             */
            '--kbb-radius:' . $c['card_radius'] . 'px',
            '--kbb-ratio:' . $ratio,
            '--kbb-sale:' . $c['sale_colour'],
            '--kbb-new:' . $c['new_colour'],
            '--kbb-price:' . $c['price_colour'],
            '--kbb-star:' . $c['star_colour'],
            '--kbb-cart-bg:' . $c['cart_bg'],
            '--kbb-cart-fg:' . $c['cart_fg'],
            // 0 means "show it all". CSS has no keyword for an unlimited
            // line clamp, so a number no name will reach stands in for it, and
            // the reserved height drops to nothing.
            '--kbb-name-lines:' . ((int) $c['name_lines'] === 0 ? 99 : $c['name_lines']),
            '--kbb-name-min:' . ((int) $c['name_lines'] === 0 ? '0' : $c['name_lines'] . ' * 1.35em'),
        ]);
    }

    /**
     * The name clamp on its own — KEPT ON PURPOSE, AND NO LONGER CALLED HERE.
     *
     * This was the whole of what this class reached the storefront with: two
     * properties on <body>, while cssVariables()' other ten went only to the
     * admin preview. layouts/store.blade.php emits cssVariables() itself now,
     * which has always carried --kbb-name-lines and --kbb-name-min as well, so
     * calling both would declare the clamp twice in one style attribute.
     *
     * ── WHY IT IS STILL HERE, WHICH IS NOT SENTIMENT ────────────────────────
     *
     * A method with no caller is exactly the shape this lane was sent to find,
     * and deleting it was the first version of this change. It is back because
     * of how code reaches this server. A package ships PHP and Blade together,
     * but storage/framework/views keys a compiled view by its SOURCE PATH and
     * decides staleness on file times — and an unzip's timestamps are not
     * reliably newer than what is already on disk. So the window where the new
     * ProductStyles.php is loaded and the OLD compiled store.blade.php is still
     * being served is real, and it is exactly the window the migration beside
     * this change exists to close.
     *
     * If that migration does not run — and CLAUDE.md's landmine list records
     * five packages whose migrations were shipped and never ran — then deleting
     * this method turns a stale compiled view into "Call to undefined method"
     * on EVERY STOREFRONT PAGE. Keeping four lines is cheap; a shop that 500s
     * until somebody with a shell clears a cache is not, on a host whose owner
     * applies packages by hand.
     *
     * It can go in a later release, once no compiled view anywhere can still
     * name it.
     */
    public function cardVariables(): string
    {
        $lines = (int) $this->all()['name_lines'];

        return '--kbb-name-lines:' . ($lines === 0 ? 99 : $lines)
             . ';--kbb-name-min:' . ($lines === 0 ? '0' : $lines . ' * 1.35em');
    }

    public function bodyClass(): string
    {
        $c = $this->all();

        return trim(implode(' ', array_filter([
            $c['show_brand'] ? '' : 'pc-nobrand',
            $c['show_category'] ? '' : 'pc-nocat',
            $c['show_rating'] ? '' : 'pc-norate',
            $c['show_was_price'] ? '' : 'pc-nowas',
            /*
             * `pc-nodisc` and `pc-nonew` ARE NOT EMITTED ANY MORE. (Lane PR)
             * components/product-card.blade.php omits the two pills itself, on
             * every page; the classes reached three pages, and `pc-nonew` would
             * also have hidden the bestsellers rail's `#1` rank pills, which
             * share `.kbb-badge-new`. The rules stay in the sheets for the admin
             * preview, which sets the classes itself.
             */
            $c['show_cart'] ? '' : 'pc-nocart',
            // Opt BACK IN to hover on a phone; see 'hover_phone' and kbb.css.
            $c['hover_phone'] ? 'pc-phonehover' : '',
        ])));
    }

    /**
     * The phone/desktop media queries cardCss() writes into. 700/701 is where
     * the Showcase card already changes (kbb.css: the heart and the button
     * size), so "phone" here means what the card itself already means by it.
     */
    private const PHONE = '@media (max-width:700px)';

    private const DESKTOP = '@media (min-width:701px)';

    /**
     * The card's spacing and type, as a stylesheet — or '' when nothing on
     * Appearance → Product styles → Spacing & type has been moved.  (Lane PR)
     *
     * ── EMPTY AT THE DEFAULTS, AND ONLY WHAT MOVED AFTER THAT ───────────────
     *
     * Every default is the Showcase card's measured value, and a stylesheet
     * restating them would be a `<style>` element on every storefront page for
     * a change that renders identically — StorefrontEnglishUnchangedTest pins
     * exactly that — and would ALSO force the Showcase numbers onto every
     * other card design, each of which has spacing of its own. So a key at its
     * default contributes nothing, and a moved key contributes one declaration.
     *
     * ── RULE 5 ON A STYLESHEET ──────────────────────────────────────────────
     *
     * Every selector, property, unit and piece of punctuation below is a
     * literal in this method. A range arrives cast and clamped to its own
     * bounds and is printed through (int); a size or weight select arrives as
     * one of its OWN option keys (FONT_SIZES / FONT_WEIGHTS) or its default —
     * cast() guarantees it — and is re-checked against that list here anyway,
     * so a value that is not on the list is dropped rather than printed.
     * Nothing a POST sends can reach a selector, a property name or a unit.
     *
     * ── WHY THE SELECTORS LOOK LIKE THIS ────────────────────────────────────
     *
     * `.kbb-pgrid[data-skin] .kbb-tile …` is (0,3,0) before the element, one
     * class above every skin rule it overrides — `.kbb-pgrid[data-skin^=
     * "showcase"] .cb` and the phone-only `.kbb-pgrid[data-skin="showcase-row"]
     * .cp` are (0,3,0) — so it wins whatever order the sheets load in. The
     * custom properties go on the GRID, doubled to (0,3,0), because the
     * Showcase family derives its reserved row heights from them there:
     * `--sc-name-slot` follows the name's size, so a larger name does not spill
     * into the price row; `--sc-brand-fs` drives both the brand line and its
     * reserved slot; `--sc-pad` moves the wishlist heart with the padding.
     */
    public function cardCss(): string
    {
        $c = $this->all();
        $moved = static fn (string $k): bool => $c[$k] !== self::SCHEMA[$k][2];
        $size = static fn (string $k): ?string => isset(self::FONT_SIZES[(string) $c[$k]]) ? (string) $c[$k] : null;
        $weight = static fn (string $k): ?string => isset(self::FONT_WEIGHTS[(string) $c[$k]]) ? (string) $c[$k] : null;

        $grid = '.kbb-pgrid.kbb-pgrid[data-skin]';
        $tile = '.kbb-pgrid[data-skin] .kbb-tile';
        $out = '';

        foreach (['m' => self::PHONE, 'd' => self::DESKTOP] as $dev => $media) {
            $rules = [];

            if ($moved("card_pad_{$dev}")) {
                $p = (int) $c["card_pad_{$dev}"];
                $rules[$grid][] = "--sc-pad:{$p}px";
                $rules["{$tile} .cb"][] = "padding-inline:{$p}px;padding-bottom:{$p}px";
            }

            if ($moved("card_gap_img_{$dev}")) {
                $rules["{$tile} .cb"][] = 'padding-top:'.(int) $c["card_gap_img_{$dev}"].'px';
            }

            /* The Showcase family reserves the brand and rating rows from these two
               custom properties, so the reservation moves with the gap and every
               card in a row stays one height. The plain margin is for the other
               designs, which reserve nothing. */
            if ($moved("card_gap_brand_{$dev}")) {
                $g = (int) $c["card_gap_brand_{$dev}"];
                $rules[$grid][] = "--sc-brand-mb:{$g}px";
                $rules["{$tile} .kbb-card-brand"][] = "margin-bottom:{$g}px";
            }

            if ($moved("card_gap_rate_{$dev}")) {
                $g = (int) $c["card_gap_rate_{$dev}"];
                $rules[$grid][] = "--sc-rate-mt:{$g}px";
                $rules["{$tile} .kbb-card-rate"][] = "margin-top:{$g}px";
            }

            if ($moved("card_gap_price_{$dev}")) {
                $rules["{$tile} .cp"][] = 'padding-top:'.(int) $c["card_gap_price_{$dev}"].'px';
            }

            if ($moved("card_gap_cart_{$dev}")) {
                $rules["{$tile} .kbb-card-cart"][] = 'margin-top:'.(int) $c["card_gap_cart_{$dev}"].'px';
            }

            if ($moved("card_fs_title_{$dev}") && ($v = $size("card_fs_title_{$dev}")) !== null) {
                $rules[$grid][] = "--sc-name-slot:calc({$v} * var(--sc-name-lh,1.32) * var(--sc-name-lines,2))";
                $rules["{$tile} .kbb-card-nm"][] = "font-size:{$v}";
            }

            if ($moved("card_fs_price_{$dev}") && ($v = $size("card_fs_price_{$dev}")) !== null) {
                $rules["{$tile} .kbb-card-price"][] = "font-size:{$v}";
            }

            if ($moved("card_fs_btn_{$dev}") && ($v = $size("card_fs_btn_{$dev}")) !== null) {
                $rules["{$tile} .kbb-card-cart"][] = "font-size:{$v}";
            }

            if ($moved("card_fs_brand_{$dev}") && ($v = $size("card_fs_brand_{$dev}")) !== null) {
                $rules[$grid][] = "--sc-brand-fs:{$v}";
                $rules["{$tile} .kbb-card-brand"][] = "font-size:{$v}";
            }

            if ($rules !== []) {
                $out .= $media.'{'.self::flatten($rules).'}';
            }
        }

        $weights = [];

        foreach ([
            'card_fw_title' => "{$tile} .kbb-card-nm",
            'card_fw_price' => "{$tile} .kbb-card-price",
            'card_fw_sale' => "{$tile} .kbb-card-reg+.kbb-card-price",
            'card_fw_btn' => "{$tile} .kbb-card-cart",
            'card_fw_brand' => "{$tile} .kbb-card-brand",
        ] as $key => $selector) {
            if ($moved($key) && ($v = $weight($key)) !== null) {
                $weights[$selector][] = "font-weight:{$v}";
            }
        }

        /*
         * The sale price IS a `.kbb-card-price` (the one after a
         * `.kbb-card-reg`), so "Price weight" on its own would repaint it too.
         * When the price weight moved and the sale weight did not, the sale
         * price is held at ITS value — the two controls stay independent.
         */
        if ($moved('card_fw_price') && ! $moved('card_fw_sale')) {
            $weights["{$tile} .kbb-card-reg+.kbb-card-price"][] = 'font-weight:'.self::SCHEMA['card_fw_sale'][2];
        }

        if ($weights !== []) {
            $out .= self::flatten($weights);
        }

        return $out;
    }

    /** @param array<string, list<string>> $rules */
    private static function flatten(array $rules): string
    {
        $css = '';

        foreach ($rules as $selector => $declarations) {
            $css .= $selector.'{'.implode(';', $declarations).'}';
        }

        return $css;
    }
}
