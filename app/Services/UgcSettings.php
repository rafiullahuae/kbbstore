<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Appearance → Video rail. Every control the rail has, on the shared shape.
 *
 * ── ONE VOCABULARY, NOT A FIFTH DIALECT ─────────────────────────────────────
 *
 * Four rounds (docs/M-PHASE3-SETTINGS-SCHEMA.md and ROUND-2..4) turned
 * ModuleSchema into a real vocabulary and the cost of a module inventing its own
 * was measured rather than argued: sixteen colour fields across four modules
 * stored a hex with no `#`, so the value saved, the admin redrew with it, and the
 * shop did not change. So this module declares SCHEMA / TABS / POLICY and casts
 * through ModuleSchema::cast(), which is the one security boundary — rule 5's
 * "a select stores one of its own options or the default" is that method's job
 * here, not this file's.
 *
 * `store` is `module` on every field: the values live in `module_settings`
 * through this module's own endpoint, so no AdminController::SETTING_RULES entry
 * is needed and the generic settings endpoint is not in the path.
 *
 * ── EVERY DEFAULT IS R3, EXACTLY ────────────────────────────────────────────
 *
 * Rule 1: a new setting ships at the value the page already has. The page has
 * nothing today (the module is OFF), so "the value the page already has" is the
 * geometry of R3 in docs/UGC-VIDEO-PREVIEWS.html — the design the owner chose
 * and then said to match "including every single thing". So:
 *
 *   tile_width 158 / desk_tile 206 / gap 12 / radius 16   R3's rail and tile
 *   cols = 'peek'                                          R3's fixed-width rail
 *   badge = false                                          R3 draws no count badge
 *   likes_on = false                                       R3 has no like button
 *   rating = true                                          R3 HAS a rating, and
 *                                                          the owner moved it into
 *                                                          the product box
 *
 * Turning any of them off or up is a departure from R3 that the owner makes
 * deliberately, on a screen that says so. `likes_on` is the one worth calling
 * out: he asked for shoppers to be able to like a clip, and the design he
 * approved has nowhere to draw that, so the control exists and ships OFF rather
 * than being added to a design he said to match exactly.
 *
 * ── THE MODULE ITSELF SHIPS OFF ─────────────────────────────────────────────
 *
 * enabled() is the only reader of `moduleEnabled('shoppable_video')` and the
 * ModuleRegistry row's default is `false`. Applying the package changes no page:
 * the shortcode renders the empty string while the module is off, so even a page
 * that already carries `[kbb_videos ...]` is byte-identical.
 */
class UgcSettings
{
    /** The ModuleRegistry key, and the `module_settings` bucket. */
    public const MODULE = 'shoppable_video';

    /**
     * The mobile column choices, in the owner's own words: "give control to show
     * 2 columns, 2.3, single column etc."
     *
     * `peek` IS 2.3-ish and is the default because it is what R3 measures: a
     * fixed 158px tile in a 390px viewport with 16px gutters and a 12px gap
     * shows 2.1 tiles and the edge of the third. The named fractions below
     * compute their width from the viewport with calc() instead, so `two` is
     * exactly two whatever the phone is, and `two_three` is exactly 2.3.
     *
     * WHY BOTH A FIXED AND A FRACTIONAL BRANCH. A fixed tile keeps the poster at
     * the size the teaser was encoded for (360x640 covers 158 CSS px at 2x with a
     * pixel to spare); a fractional tile on a 430px phone is 178px wide, which is
     * still inside that encode, and on a 320px phone it is 133px, which is
     * sharper than it needs to be. Neither is wrong — the fixed one is what was
     * designed and measured, and is therefore the default.
     */
    public const COLUMNS = [
        'peek' => '2.3 across — the peeking rail, as designed',
        'one' => '1 — one tile fills the screen',
        'one_peek' => '1.2 — one tile, the next peeking',
        'two' => '2 — exactly two, side by side',
        'two_three' => '2.3 — two and a third, computed from the screen',
        'three' => '3 — three across',
        'grid_two' => 'Two-column grid — wraps instead of scrolling sideways',
    ];

    public const SCHEMA = [
        /* ── Layout ─────────────────────────────────────────────────────── */
        'cols' => ['type' => 'select', 'label' => 'Tiles across on a phone', 'default' => 'peek',
            'help' => 'The default is the rail R3 was designed and measured at: a fixed 158px tile, so two and a bit fit a 390px phone and the next one peeks.',
            'options' => self::COLUMNS, 'store' => ModuleSchema::STORE_MODULE],
        'tile_w' => ['type' => 'range', 'label' => 'Tile width on a phone', 'default' => 158,
            'help' => 'Used only by the 2.3-as-designed option above — the computed options size themselves from the screen.',
            'options' => ['min' => 120, 'max' => 240, 'step' => 2, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],
        'desk_tile' => ['type' => 'range', 'label' => 'Tile width from 900px', 'default' => 206,
            'help' => 'R3 measures 206px on a desktop.',
            'options' => ['min' => 150, 'max' => 320, 'step' => 2, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],
        'gap' => ['type' => 'range', 'label' => 'Space between tiles', 'default' => 12, 'help' => '',
            'options' => ['min' => 4, 'max' => 28, 'step' => 1, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],
        'radius' => ['type' => 'range', 'label' => 'Corner radius', 'default' => 16,
            'help' => 'R3 uses the 16px medium radius.',
            'options' => ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],

        /* ── Motion ─────────────────────────────────────────────────────── */
        'teaser' => ['type' => 'bool', 'label' => 'Loop the first 2–3 seconds', 'default' => true,
            'help' => 'Off shows the poster frame instead, which is what a phone asking to Save Data already gets. The loop costs about 129KB a tile; the poster costs about 22KB.',
            'store' => ModuleSchema::STORE_MODULE],
        'teaser_ms' => ['type' => 'range', 'label' => 'How long the loop runs before repeating', 'default' => 2500,
            'help' => 'Only used when a clip has no separate teaser file and the loop has to be cut from the full one.',
            'options' => ['min' => 1500, 'max' => 4000, 'step' => 100, 'unit' => 'ms'], 'store' => ModuleSchema::STORE_MODULE],
        'max_playing' => ['type' => 'range', 'label' => 'Most clips moving at once', 'default' => 4,
            'help' => 'A phone never gets more than two tiles over the threshold at 390px, so this binds on a desktop.',
            'options' => ['min' => 1, 'max' => 6, 'step' => 1, 'unit' => ''], 'store' => ModuleSchema::STORE_MODULE],
        'autoplay_open' => ['type' => 'bool', 'label' => 'Play automatically when a shopper opens a clip', 'default' => true,
            'help' => 'A tap is intent, so the opened player starts on its own. Off makes the shopper press play twice.',
            'store' => ModuleSchema::STORE_MODULE],
        'controls' => ['type' => 'bool', 'label' => 'Show the player’s own controls', 'default' => true,
            'help' => 'The scrubber, the volume and the full-screen button, drawn by the browser.',
            'store' => ModuleSchema::STORE_MODULE],
        'sound_on_open' => ['type' => 'bool', 'label' => 'Unmute when a shopper opens a clip', 'default' => false,
            'help' => 'Off is the shipped behaviour and the safe one: a rail that makes noise on a tap is the thing people leave a page over. The tile itself is always muted — no browser autoplays sound.',
            'store' => ModuleSchema::STORE_MODULE],

        /* ── What a tile shows ──────────────────────────────────────────── */
        'rating' => ['type' => 'bool', 'label' => 'Rating bar inside the product box', 'default' => true,
            'help' => 'The product’s real rating and review count, as a thin line inside the white card. A product with no reviews shows no bar at all.',
            'store' => ModuleSchema::STORE_MODULE],
        'caption' => ['type' => 'bool', 'label' => 'The creator’s caption', 'default' => true, 'help' => '',
            'store' => ModuleSchema::STORE_MODULE],
        'handle' => ['type' => 'bool', 'label' => 'The creator’s handle', 'default' => true,
            'help' => 'Credit, and a link back to the original post where there is one. Turning this off on a clip you re-hosted is not a good idea.',
            'store' => ModuleSchema::STORE_MODULE],
        'badge' => ['type' => 'bool', 'label' => 'A “3 products” badge on the poster', 'default' => false,
            'help' => 'R3 does not draw one — the card on the poster already says what is for sale.',
            'store' => ModuleSchema::STORE_MODULE],
        'strike' => ['type' => 'bool', 'label' => 'Strike the old price and show the discount', 'default' => true,
            'help' => 'As R3 draws it: the charged price, the pre-sale figure struck through, and the percentage.',
            'store' => ModuleSchema::STORE_MODULE],

        /* ── Likes ──────────────────────────────────────────────────────── */
        /*
         * ONE CONTROL, AND IT IS OURS. An earlier draft of this round also had a
         * select for showing a source's like and comment counts; the owner cut it
         * — "leave the counts for now, just get the videos from there" — and the
         * fetchers, the credentials and the columns behind it were deleted rather
         * than left inert. docs/UGC-ENGAGEMENT.md records what each platform would
         * have cost so nobody researches it twice.
         *
         * OFF, because R3 draws no like button and the owner asked for R3 exactly.
         * Turning it on adds a pill to the top corner of every tile.
         */
        'likes_on' => ['type' => 'bool', 'label' => 'Let shoppers like a clip', 'default' => false,
            'help' => 'A heart on each tile with the number of shoppers who have pressed it. One like per browser, rate limited, and nothing personal is stored — no address, no account, just a random token this shop mints. R3 has no heart on it, so this ships off.',
            'store' => ModuleSchema::STORE_MODULE],
    ];

    public const TABS = [
        'layout' => ['Layout', 'How many tiles a shopper sees, and how big they are. The defaults are the measured geometry of R3.', ['cols', 'tile_w', 'desk_tile', 'gap', 'radius']],
        'motion' => ['Motion', 'The 2–3 second loop, and what happens when somebody taps.', ['teaser', 'teaser_ms', 'max_playing', 'autoplay_open', 'controls', 'sound_on_open']],
        'tile' => ['What a tile shows', 'Every one of these ships at what R3 draws.', ['rating', 'caption', 'handle', 'badge', 'strike']],
        'likes' => ['Likes', 'Whether a shopper can like a clip, and see how many others have.', ['likes_on']],
    ];

    /**
     * This module's point on ModuleSchema's policy axes.
     *
     * `invalid => default` and `clamp => true`: a slider posted outside its range
     * is pulled to the bound and an unrecognised select value becomes the shipped
     * default — which is rule 5's "a select stores one of its own options or the
     * default", and is what makes a hand-rolled POST of `cols=<script>` store
     * `peek`.
     *
     * `blank => default`: there is no free-text field on this screen, so the axis
     * is only ever reached by a select or a range arriving empty, and for those
     * the shipped value is the only sensible answer.
     *
     * `hex` and `markup` are at DEFAULT_POLICY's strict end and are never
     * exercised: this module declares no colour and no markup field. Left at the
     * strict end deliberately — a module has to ask for leniency in writing.
     */
    public const POLICY = [
        'max' => 120,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The one storefront reader of the module switch.
     *
     * ModuleRegistry marks the row `live`, and ModuleFrameworkGuardTest greps
     * app/ and resources/views/ for a literal moduleEnabled('<key>') to prove
     * that claim rather than trusting it. This is that call, and the default is
     * FALSE, so applying the package leaves the shop exactly as it was.
     */
    public function enabled(): bool
    {
        /*
         * A LITERAL KEY AND NOT self::MODULE, and that is not a style choice.
         * ModuleFrameworkGuardTest TOKENISES app/ and resources/views/ looking for
         * moduleEnabled() with a T_CONSTANT_ENCAPSED_STRING first argument — that is
         * how it proves a `live` registry row has a real reader rather than trusting
         * the row. Written `moduleEnabled(self::MODULE, false)` the call is invisible
         * to it and the guard reported this module as live with nothing reading its
         * switch, which is exactly the fault it exists to catch (seo_engine and
         * product_sorting both shipped that way). Found by running it.
         *
         * `false` is the default, so a store that has saved nothing is OFF.
         */
        return $this->settings->moduleEnabled('shoppable_video', false);
    }

    /**
     * ── WHY THIS IS NOT ModuleSchema::read() / ::write() ────────────────────
     *
     * Because those two are POLICY-BLIND, and this module's policy is not the
     * strict default. Both call `self::normalise($schema)` with NO policy
     * argument, so every field they touch resolves to ModuleSchema::DEFAULT_POLICY
     * — `invalid => reject`, `clamp => false`, `bool => words`.
     *
     * THAT WAS NOT A THEORY. The first version of this class used them, and a
     * `cols` posted as something that is not one of UgcSettings::COLUMNS came back
     * in the response's `rejected` list instead of being stored as the shipped
     * default, and a `gap` of 9999 was refused instead of being clamped to 28 —
     * so the screen reported an error for a value rule 5 says should quietly
     * become the default. Found by the test, not by reading.
     *
     * So this module reads and writes through its own two methods, passing POLICY
     * to ModuleSchema::cast(), which is exactly what App\Services\SectionDividers
     * already does and for the same reason. The `store` routing the shared
     * versions provide is not needed here: every field on this schema is
     * `STORE_MODULE`, and ModuleFrameworkGuardTest-shaped checks live in
     * UgcSectionAdminTest.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $this->settings->moduleSetting(self::MODULE, $key, null);

            // A store that has saved nothing gets the SHIPPED default — which is
            // rule 1: applying this package moves no value.
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * Writes the keys the payload actually carries, each through cast().
     *
     * Only keys in the schema, so an unknown key cannot ride in; only keys
     * present, so a screen that posts one tab does not blank the others. A refused
     * value is RETURNED rather than dropped — a save that quietly discarded a bad
     * number is the silence ModuleSchema exists to remove.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY);
        $written = [];
        $rejected = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->setModuleSetting(self::MODULE, $key, $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /**
     * The CSS custom properties the rail is sized with — and the reason there is
     * no JavaScript anywhere in this feature that measures anything.
     *
     * Every number a tile needs is a `calc()` input on the section element, so
     * the browser lays the rail out once from the stylesheet. Rule 4 forbids the
     * element-measuring APIs by name (CheckoutFloatingBarGateTest and
     * CartPageSqueezeTest name getBoundingClientRect, offsetTop, offsetHeight,
     * clientHeight and scrollY), and this is the sanctioned answer rather than a
     * workaround for it.
     *
     * `--ugc-w` is the ONE property the column options differ in:
     *
     *   peek        the measured 158px (tile_w), fixed
     *   one         100% of the track
     *   one_peek    100%/1.2, so the next tile shows
     *   two/2.3/3   100%/n, with the gaps subtracted exactly rather than
     *               approximately — n tiles carry (n-1) whole gaps plus the
     *               fraction of one belonging to the partly-visible tile, which
     *               for 2.3 is 1.3 gaps. Getting that wrong is how a "2 across"
     *               rail overflows by 24px.
     *
     * @param  array<string, mixed>  $values
     */
    public static function cssVariables(array $values, ?string $columnsOverride = null): string
    {
        $cols = $columnsOverride !== null && isset(self::COLUMNS[$columnsOverride])
            ? $columnsOverride
            : (string) ($values['cols'] ?? 'peek');

        $gap = (int) ($values['gap'] ?? 12);

        $width = match ($cols) {
            'one' => '100%',
            'one_peek' => 'calc((100% - '.($gap * 0.2).'px) / 1.2)',
            'two' => 'calc((100% - '.$gap.'px) / 2)',
            'two_three' => 'calc((100% - '.($gap * 1.3).'px) / 2.3)',
            'three' => 'calc((100% - '.($gap * 2).'px) / 3)',
            // grid_two is a grid, not a track: the stylesheet ignores --ugc-w.
            'grid_two' => 'auto',
            default => (int) ($values['tile_w'] ?? 158).'px',
        };

        return implode(';', [
            '--ugc-w:'.$width,
            '--ugc-dw:'.(int) ($values['desk_tile'] ?? 206).'px',
            '--ugc-gap:'.$gap.'px',
            '--ugc-r:'.(int) ($values['radius'] ?? 16).'px',
        ]);
    }
}
