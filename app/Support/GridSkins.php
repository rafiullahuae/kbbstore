<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The 28 product-grid card templates.
 *
 * Every skin is pure CSS over one markup shape, so switching is a single
 * attribute and costs nothing at render time.
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
