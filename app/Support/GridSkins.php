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

    public const DEFAULT = 'classic';

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
