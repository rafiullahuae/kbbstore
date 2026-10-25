<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The account area's line icons (Lane ACC): 24px stroke paths, drawn inline so
 * the pages load no icon file and make no extra request. Every one carries its
 * own width/height (CLAUDE.md: an inline svg without them can blow up when a
 * stylesheet is late) and aria-hidden, because the word beside it is the label.
 * Constants only: nothing from a setting or a request reaches this markup.
 */
final class AccountIcons
{
    private const PATHS = [
        'back' => '<path d="M15 18l-6-6 6-6"/>',
        'chev' => '<path d="M9 6l6 6-6 6"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'check' => '<path d="M5 12l5 5 9-10"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'wallet' => '<path d="M3 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/><path d="M3 7l3-4h11v4"/>',
        'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
        'pack' => '<path d="M4 7l8-4 8 4v10l-8 4-8-4z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'x' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'bag' => '<path d="M6 7h12l1 14H5z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
    ];

    public static function svg(string $name, int $size = 18, string $class = ''): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['chev'];

        return '<svg' . ($class !== '' ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" width="' . $size . '" height="' . $size
            . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths . '</svg>';
    }

    /** The strip's icon for a tone from OrderProgress::tone(). */
    public static function forTone(string $tone): string
    {
        return ['pay' => 'wallet', 'hold' => 'pause', 'proc' => 'pack', 'ship' => 'truck', 'done' => 'check', 'off' => 'x'][$tone] ?? 'box';
    }
}
