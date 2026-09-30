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
        'show_discount'      => ['bool',   'Discount badge', true, ''],
        'show_new'           => ['bool',   'New badge', true, 'On products with no reviews yet.'],
        'show_cart'          => ['bool',   'Add to cart button', true, ''],
        'name_lines'         => ['range',  'Product name lines', 0, 'Zero shows the whole name, however long. One to four trims it.', ['min' => 0, 'max' => 4, 'step' => 1, 'unit' => '']],

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

    public const TABS = [
        'layout'  => ['Layout', 'Card shape and corners.',
                      ['grid_skin', 'card_radius', 'image_ratio']],
        'content' => ['Card content', 'What each card shows.',
                      ['show_brand', 'show_category', 'show_rating', 'show_was_price', 'show_discount', 'show_new', 'show_cart', 'name_lines']],
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
            $c['show_discount'] ? '' : 'pc-nodisc',
            $c['show_new'] ? '' : 'pc-nonew',
            $c['show_cart'] ? '' : 'pc-nocart',
        ])));
    }
}
