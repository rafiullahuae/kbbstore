<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Content → Instagram → Look. Every control the section has, on the shared shape.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md §9 is the layout table; this is it in code.
 *
 * ── ONE VOCABULARY, NOT A SIXTH DIALECT ─────────────────────────────────────
 *
 * SCHEMA / TABS / POLICY, cast through ModuleSchema::cast(), exactly as
 * App\Services\UgcSettings does and for the reason docs/M-PHASE3-SETTINGS-SCHEMA.md
 * measured: sixteen colour fields across four modules stored a hex with no `#`
 * because there were four copies of the same three lines and nothing tied them
 * together. Rule 5's "a select stores one of its own options or the default" is
 * ModuleSchema::cast()'s job here, not this file's.
 *
 * ── EVERY DEFAULT IS "WHAT THE PAGE ALREADY HAS", WHICH IS NOTHING ──────────
 *
 * Rule 1. The module ships OFF and no page draws this section today, so there is no
 * existing appearance to preserve and the defaults are instead the CHEAPEST and
 * most conservative reading of the owner's request:
 *
 *   layout = 'grid'      what he asked for in as many words: "a nice grid type
 *                        instagram section". Instagram's own 3-across.
 *   profile_style='card' "our profile box should also show".
 *   counts = true        "all likes etc should be show". A count is still drawn
 *                        only when we HAVE one — see the note on it below.
 *   tap = 'permalink'    NOT the in-page player, and this is the one default worth
 *                        arguing about. See `tap`.
 *   posts = 9            three rows of three. A grid of 9 is a section; a grid of
 *                        30 is a page.
 *
 * ── THE MODULE ITSELF SHIPS OFF ─────────────────────────────────────────────
 *
 * enabled() is the only reader of moduleEnabled('instagram_profile') and the
 * ModuleRegistry row's default is false. Applying the package changes no page: the
 * shortcode renders the empty string and the homepage section draws no element, so
 * even a page that already carries [kbb_instagram] is byte-identical.
 */
class InstagramSettings
{
    /** The ModuleRegistry key, and the `module_settings` bucket. */
    public const MODULE = 'instagram_profile';

    /**
     * The five layouts, in the owner's own words: "please prepare multiple layouts
     * for the instagram section, so i can choose from."
     *
     * ── ALL FIVE ARE ONE CLASS AND A calc(), AND THE TILE MARKUP IS IDENTICAL ─
     *
     * Rule 4 forbids JavaScript that measures layout, by name — two tests name
     * getBoundingClientRect, offsetTop, offsetHeight, clientHeight and scrollY. So
     * a layout here is never "measure the container and divide": it is a class on
     * the section element and a `--ig-w` the stylesheet computes with calc(), which
     * is the same answer App\Services\UgcSettings::cssVariables() already gives for
     * the video rail. The browser lays each one out once, from the stylesheet.
     *
     * And the TILE is the same markup in all five. That is a security property as
     * much as a maintenance one: five layouts written as five templates is five
     * places for the caption escaping to be got right, and the fifth one is the one
     * nobody reviews.
     */
    public const LAYOUTS = [
        'grid' => 'Square grid — Instagram’s own, three across (two on a phone)',
        'rail' => 'Peeking rail — one row, scrolls sideways, the next tile peeking',
        'mosaic' => 'Mosaic — the newest post twice the size, the rest square around it',
        'masonry' => 'Tall cards — 4:5 portrait tiles, the shape a reel is shot in',
        'strip' => 'Slim strip — one short row of small tiles, for a footer or a sidebar',
    ];

    /**
     * How the profile box is drawn, or whether it is.
     *
     * Its own control rather than a corner of `layout`, because the owner asked for
     * it as its own thing — "and also our profile box should also show" — and
     * because the two choices are genuinely independent: a slim strip in a footer
     * wants no profile box and a square grid on the homepage wants a big one.
     */
    public const PROFILE_STYLES = [
        'card' => 'Card above the grid — avatar, name, followers and a Follow button',
        'bar' => 'One slim line above the grid',
        'inline' => 'The avatar becomes the first tile in the grid',
        'off' => 'No profile box at all',
    ];

    /** What a tap on a tile does. See the `tap` field for why the default is this. */
    public const TAPS = [
        'permalink' => 'Open the post on Instagram, in a new tab',
        'embed' => 'Play it here, in a box over the page (needs one security setting — see below)',
        'nothing' => 'Nothing — the tiles are pictures, not links',
    ];

    public const SCHEMA = [
        /* ── Look ───────────────────────────────────────────────────────── */
        'layout' => ['type' => 'select', 'label' => 'Layout', 'default' => 'grid',
            'help' => 'All five are drawn with CSS alone, so they cost the page nothing to choose between. Square grid is what Instagram itself uses.',
            'options' => self::LAYOUTS, 'store' => ModuleSchema::STORE_MODULE],
        'profile_style' => ['type' => 'select', 'label' => 'The profile box', 'default' => 'card',
            'help' => 'Our own avatar, name and follower count. Nothing is drawn at all until the connection has fetched a profile, whichever of these is picked.',
            'options' => self::PROFILE_STYLES, 'store' => ModuleSchema::STORE_MODULE],
        'posts' => ['type' => 'range', 'label' => 'How many posts', 'default' => 9,
            'help' => 'Nine is three rows of three. The grid never shows more than this even if more have been fetched, and never shows a post whose picture failed to download.',
            'options' => ['min' => 3, 'max' => 24, 'step' => 1, 'unit' => ''], 'store' => ModuleSchema::STORE_MODULE],
        'gap' => ['type' => 'range', 'label' => 'Space between tiles', 'default' => 8,
            'help' => 'Instagram’s own grid uses a hairline. Bigger values read as a gallery rather than a feed.',
            'options' => ['min' => 0, 'max' => 24, 'step' => 1, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],
        'radius' => ['type' => 'range', 'label' => 'Corner radius', 'default' => 10,
            'help' => '0 is Instagram’s own square-cornered grid.',
            'options' => ['min' => 0, 'max' => 28, 'step' => 1, 'unit' => 'px'], 'store' => ModuleSchema::STORE_MODULE],
        'heading' => ['type' => 'text', 'label' => 'Heading above the section', 'default' => 'Follow us on Instagram',
            'help' => 'Empty draws no heading element at all.',
            'store' => ModuleSchema::STORE_MODULE],

        /* ── What a tile shows ──────────────────────────────────────────── */
        /*
         * ── `counts` IS "SHOW THEM IF WE HAVE THEM", NOT "SHOW A NUMBER" ─────
         *
         * The owner asked for "all likes etc should be show", so this is ON. What
         * it cannot do is invent one: docs/UGC-ENGAGEMENT.md paid for the rule and
         * its wording is exact — "A NUMBER THIS SHOP CANNOT VERIFY IS NOT PRINTED.
         * Not zero. Not a dash where a figure should be. The element is not drawn
         * at all."
         *
         * So with this on, a post whose `like_count` is NULL draws no like element,
         * and a post whose count is genuinely 0 draws "0". Those are different
         * facts and the template can tell them apart because InstagramPost keeps
         * the column nullable with no default and compares with === null.
         */
        'counts' => ['type' => 'bool', 'label' => 'Likes and comments on each tile', 'default' => true,
            'help' => 'Instagram’s real numbers for our own posts, as of the last refresh. A post Instagram gave us no number for shows no number — never a zero, because a zero under a reel with thousands of likes is this shop telling a shopper something false.',
            'store' => ModuleSchema::STORE_MODULE],
        'caption' => ['type' => 'bool', 'label' => 'The caption, on hover or tap', 'default' => false,
            'help' => 'Off keeps the grid clean, which is what Instagram’s own grid does. On, the first line of the caption sits over the picture.',
            'store' => ModuleSchema::STORE_MODULE],
        'play_badge' => ['type' => 'bool', 'label' => 'A play triangle on reels and videos', 'default' => true,
            'help' => 'Drawn from the media type Instagram reported, so a still never gets one.',
            'store' => ModuleSchema::STORE_MODULE],
        /*
         * ── `tap` SHIPS AT `permalink`, AND THAT IS THE HONEST DEFAULT ───────
         *
         * The owner asked for "playable on our site directly from instagram, like
         * embed type", and `embed` is that — Instagram's own iframe, which plays
         * the reel in place with no token and nothing that can expire.
         *
         * It ships OFF anyway, for one reason and it is written on the screen
         * rather than buried here: the iframe is on www.instagram.com and
         * App\Services\Security\ContentSecurityPolicy's `frame-src` lists only
         * Stripe. The policy is REPORT-ONLY today, so the embed works right now
         * and files a violation report — but the round that enforces will break it,
         * and shipping a default that a later security round silently kills is
         * worse than shipping the floor and saying how to raise it.
         *
         * docs/IG-PROFILE.md §3 has the exact one-line diff that directive needs.
         * This lane did not make it: rule 5's spirit is that a policy is widened
         * deliberately by the round that owns it, not as a side effect of a feature.
         */
        'tap' => ['type' => 'select', 'label' => 'What a tap on a tile does', 'default' => 'permalink',
            'help' => 'Opening Instagram in a new tab always works and needs nothing. Playing it here uses Instagram’s own embed — which also needs https://www.instagram.com added to frame-src in Store → Security → Content Security Policy, or the round that enforces that policy will stop it playing.',
            'options' => self::TAPS, 'store' => ModuleSchema::STORE_MODULE],
    ];

    public const TABS = [
        'look' => ['Look', 'Which of the five layouts, how big, and whether the profile box shows.',
            ['layout', 'profile_style', 'posts', 'gap', 'radius', 'heading']],
        'tile' => ['What a tile shows', 'The numbers, the caption and what happens on a tap.',
            ['counts', 'caption', 'play_badge', 'tap']],
    ];

    /**
     * This module's point on ModuleSchema's policy axes.
     *
     * The same point App\Services\UgcSettings declares, and for the same measured
     * reason rather than by copying: `invalid => default` and `clamp => true` mean a
     * hand-rolled POST of `layout=<script>` stores `grid` and a `gap` of 9999 is
     * pulled to 24, instead of coming back in a `rejected` list the screen then has
     * to explain. `blank => keep` is where this module DIFFERS from UgcSettings, and
     * it has to: `heading` is a free-text box whose empty value is meaningful —
     * "draw no heading" — and `blank => default` would put "Follow us on Instagram"
     * back every time somebody cleared it.
     *
     * `hex` and `markup` stay at DEFAULT_POLICY's strict end. This module declares
     * no colour and no markup field, and leniency is something a module has to ask
     * for in writing.
     */
    public const POLICY = [
        'max' => 120,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The one storefront reader of the module switch.
     *
     * A LITERAL KEY AND NOT self::MODULE, and that is not a style choice.
     * ModuleFrameworkGuardTest TOKENISES app/ and resources/views/ looking for
     * moduleEnabled() with a T_CONSTANT_ENCAPSED_STRING first argument — that is how
     * it proves a `live` registry row has a real reader rather than trusting the
     * row. Written moduleEnabled(self::MODULE, false) the call is invisible to it,
     * which is the fault that guard exists to catch: UgcSettings' own docblock
     * records hitting it, and seo_engine and product_sorting both shipped that way.
     *
     * `false` is the default, so a store that has saved nothing is OFF.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('instagram_profile', false);
    }

    /**
     * Every setting, each through cast(), with the shipped default for anything
     * unsaved.
     *
     * NOT ModuleSchema::read(), for the reason UgcSettings spells out at length:
     * read() and write() are POLICY-BLIND — both call normalise() with no policy
     * argument, so every field resolves to DEFAULT_POLICY's `invalid => reject` and
     * `clamp => false`, and a `layout` posted as something that is not one of
     * LAYOUTS would come back as an error instead of quietly becoming `grid`.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY) as $key => $field) {
            $saved = $this->settings->moduleSetting(self::MODULE, $key, null);

            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * Writes the keys the payload actually carries, each through cast().
     *
     * Only keys in the schema, so an unknown key cannot ride in; only keys present,
     * so a screen that posts one tab does not blank the other. A refused value is
     * RETURNED rather than dropped.
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

        InstagramFeed::flush();

        return ['written' => $written, 'rejected' => $rejected];
    }

    /* --------------------------------------------------------------- the profile */

    /**
     * The profile blob the last fetch stored, or [].
     *
     * ── IN `module_settings` AND NOT IN A TABLE OF ITS OWN ──────────────────
     *
     * There is exactly one of these — we have one Instagram account — so a table
     * would be a table with one row in it, a migration, a model and a query the
     * storefront has to make. `module_settings` is read as ONE cached snapshot
     * (SettingsService::moduleSettingsMap()), which this module is already reading
     * for its six appearance settings, so the profile box costs the page NO
     * additional query at all. That is the whole reason, and it is a cost decision
     * rather than a modelling one.
     *
     * Not a secret and not a credential: a username, a name, a follower count and
     * an avatar URL, all of them on the public profile page already. The token and
     * the app secret are in InstagramCredentials, encrypted, and never here.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $raw = $this->settings->moduleSetting(self::MODULE, 'profile', null);

        return is_array($raw) ? $raw : [];
    }

    /** @param  array<string, mixed>  $profile */
    public function saveProfile(array $profile): void
    {
        $this->settings->setModuleSetting(self::MODULE, 'profile', $profile);

        InstagramFeed::flush();
    }

    public function forgetProfile(): void
    {
        $this->settings->setModuleSetting(self::MODULE, 'profile', []);

        InstagramFeed::flush();
    }

    /**
     * The CSS custom properties the section is sized with.
     *
     * ── AND THE REASON THERE IS NO MEASURING JAVASCRIPT ANYWHERE HERE ───────
     *
     * Every number a tile needs is a calc() input on the section element, so the
     * browser lays the grid out once from the stylesheet. Rule 4 forbids the
     * element-measuring APIs by name, and this is the sanctioned answer rather than
     * a workaround for it — the same one UgcSettings::cssVariables() gives.
     *
     * `--ig-w` is only read by the two layouts that are TRACKS (`rail`, `strip`):
     * a horizontal scroller has to be told a tile width because a percentage of a
     * scrolling track is not a percentage of the screen. `grid`, `mosaic` and
     * `masonry` are CSS grids and size their own columns from `repeat()`, so they
     * ignore it — which is why the gap arithmetic below only has to be right for
     * the two that scroll.
     *
     * The gap subtraction is exact rather than approximate, for the reason
     * UgcSettings records: n tiles carry (n-1) whole gaps plus the fraction of one
     * belonging to the partly visible tile, and getting that wrong is how a rail
     * overflows by 24px.
     *
     * @param  array<string, mixed>  $values
     */
    public static function cssVariables(array $values): string
    {
        $gap = (int) ($values['gap'] ?? 8);
        $layout = (string) ($values['layout'] ?? 'grid');

        // A peeking rail shows 2.3 tiles on a phone, so it carries 1.3 gaps; the
        // slim strip shows 4.5 small ones and carries 3.5.
        $width = match ($layout) {
            'rail' => 'calc((100% - '.($gap * 1.3).'px) / 2.3)',
            'strip' => 'calc((100% - '.($gap * 3.5).'px) / 4.5)',
            default => 'auto',
        };

        return implode(';', [
            '--ig-w:'.$width,
            '--ig-gap:'.$gap.'px',
            '--ig-r:'.(int) ($values['radius'] ?? 10).'px',
        ]);
    }
}
