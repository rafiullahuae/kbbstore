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
 * So the number the owner sets here is a TILE MINIMUM, not a count. `tile`
 * ships at 260px, which is the value that reproduces today's rendered count at
 * 320, 360, 390, 414, 480, 600, 768, 834, 1024, 1280 and 1366 — and gives
 * FIVE at 1680 where today gives four, which is the one extra column he asked
 * for, arrived at by arithmetic rather than by a new breakpoint. Every cell
 * that moves is in docs/W1-SITE-WIDTH.md, with the width it moved at.
 *
 * ── WHAT SHIPS CHANGED, AND IT IS EXACTLY ONE THING ─────────────────────────
 *
 * `max` ships at 1680px. That is a real change to the rendered shop on the
 * home page (1352 → 1680), /shop (1240 → 1680), a product page (1180 → 1680)
 * and the Journal (1160 → 1680), and it is the default the owner asked for in
 * as many words. It is called out in the commit rather than buried.
 *
 * EVERY OTHER SETTING HERE SHIPS AT THE VALUE THE PAGE ALREADY HAD: the
 * gutter at 22px (kbb.css's generic `.wrap` padding), the tile at 260px, the
 * column floor at 2 (what the phone already showed), the cap at 8 (which is
 * more columns than --kbb-tile will ever allow, so it is inert until moved),
 * the gap at 16px, the pin at `auto`, and `header_follows` OFF so the header
 * keeps its own 1280px until somebody says otherwise.
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
        'header_follows' => ['bool', 'Header follows the site width', false,
            'Off: the header keeps its own Content width from Appearance → Header, which is 1280px. On: the header is exactly as wide as the page.'],

        // ── Product grid ──
        'tile' => ['range', 'Smallest card', 260,
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
    ];

    public const TABS = [
        'width' => ['Page width',
            'One number for the whole shop. The cart page, the checkout and the slim footer keep their own width sliders on their own screens — they are pages asking for money, and a narrow ledger there is deliberate.',
            ['max', 'gutter', 'gutter_wide', 'header_follows']],
        'grid' => ['Product grid',
            'The column count is not set here — it is worked out from the smallest card and the width each grid actually has, so a grid beside the shop filters gets the right answer rather than the window\'s answer.',
            ['tile', 'tile_shop', 'cols_floor', 'cols_cap', 'gap', 'pin']],
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
            if ($values[$key] !== $field['default']) {
                return false;
            }
        }

        return true;
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
         * The header is the one width on this screen that another screen
         * already owns. Appearance → Header's `max_width` writes --hd-max, and
         * its slider stops at 1600px — below the 1680 asked for here — so a
         * shop that wants one width everywhere cannot say so there. This switch
         * says "use the page width" and writes the SAME property rather than
         * adding a second number beside it. Off by default, so the header keeps
         * its 1280px.
         */
        if ($c['header_follows']) {
            $out[] = '--hd-max:var(--site-max)';
        }

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
