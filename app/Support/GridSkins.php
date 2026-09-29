<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The 32 product-grid card templates.
 *
 * Every skin is pure CSS over one markup shape, so switching is a single
 * attribute and costs nothing at render time. 28 of them came across from
 * kbb-grid-skins.html; the last four are the showcase family below.
 */
final class GridSkins
{
    /** name => human label, in the order the design file presents them. */
    public const ALL = [
        'classic' => 'Classic card',
        'overlay' => 'Overlay',
        'spotlight' => 'Spotlight',
        'editorial' => 'Editorial',
        'minimal' => 'Minimal',
        'horizontal' => 'Horizontal',
        'soft' => 'Soft — pastel',
        'bold' => 'Bold — dark luxury',
        'glass' => 'Glass — frosted',
        'split' => 'Split — colour block',
        'round' => 'Round — lookbook',
        'polaroid' => 'Polaroid — photo frame',
        'stacked' => 'Stacked — floating panel',
        'petal' => 'Petal — organic',
        'glow' => 'Glow — rose halo',
        'luxe' => 'Luxe — cream & gold',
        'pastel' => 'Pastel — soft gradient',
        'rosegold' => 'Rose Gold — gradient accent',
        'actions' => 'Actions — hover bar',
        'ribbon' => 'Ribbon — sale corner',
        'frame' => 'Frame — bordered',
        'duotone' => 'Duotone — tinted',
        'magazine' => 'Magazine — big type',
        'fab' => 'FAB — add button',
        'outline' => 'Outline — hover border',
        'pricetag' => 'Price tag — floating',
        'reveal' => 'Reveal — hover info',
        'accentbar' => 'Accent bar — left',

        /*
         * ── THE SHOWCASE FAMILY ────────────────────────────────── Lane PG2 ──
         *
         * Four treatments of one card — the design the owner attached, and
         * three that take it somewhere. They are the LAST four on purpose: the
         * picker on Appearance → Product styles → Layout reads this array in
         * order, and the 28 above it are the set he has already been choosing
         * from.
         *
         * THE NAMES SHARE A PREFIX AND THAT IS LOAD-BEARING, not tidiness:
         * kbb-grid-skins.css writes the whole family against
         * `.kbb-pgrid[data-skin^="showcase"]` and each treatment against its own
         * exact name, so a fifth treatment is a row here plus a handful of
         * custom properties there. A name that does not start `showcase` gets
         * none of the family block and renders as the bare card.
         */
        'showcase' => 'Showcase — full-width button',
        'showcase-compact' => 'Showcase Compact — tighter',
        'showcase-row' => 'Showcase Row — price beside the button',
        'showcase-airy' => 'Showcase Airy — more air, lighter button',
    ];

    /*
     * ── THE SHIPPED DEFAULT, AND IT MOVED ──────────────────────── Lane PG2 ──
     *
     * The owner, in as many words: "apply this design on the whole website
     * everywhere. exept cart and checkout pages. keep this design by default
     * from backend."
     *
     * That is CLAUDE.md rule 1's one exception — "a default the owner asked for
     * in as many words" — so it is called out here and in the commit rather
     * than buried. It was `classic` for every release before this one.
     *
     * THIS CONSTANT AND FOUR OTHER PLACES ARE THE WHOLE SWITCH, and they are
     * listed so that choosing a different treatment is one edit per line rather
     * than a hunt:
     *
     *   here                                        every grid that does not
     *                                               choose its own
     *   ProductStyles::SCHEMA['grid_skin']          what the admin screen calls
     *                                               "Default card style"
     *   HomepageSections::REGISTRY, four rows       the homepage's own rails,
     *                                               which DO choose their own
     *   the two `grid_skin` fallbacks in
     *   store/wishlist.blade.php and
     *   store/collection.blade.php                  a shop that has never saved
     *                                               the setting at all
     *
     * DefaultCardStyleTest pins all five together, so a change to one of them
     * alone is red.
     */
    public const DEFAULT = 'showcase';

    /**
     * An explicit skin wins; otherwise the store setting; otherwise the default.
     * An unknown name falls back rather than rendering an unstyled grid.
     */
    public static function resolve(?string $skin = null): string
    {
        if ($skin !== null && isset(self::ALL[$skin])) {
            return $skin;
        }

        $configured = (string) app(SettingsService::class)->get('grid_skin', self::DEFAULT);

        return isset(self::ALL[$configured]) ? $configured : self::DEFAULT;
    }

    public static function exists(string $skin): bool
    {
        return isset(self::ALL[$skin]);
    }
}
