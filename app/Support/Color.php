<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ported from kbb_darken(). Used for the brand accent, where the deep shade is
 * derived rather than configured so the two can never drift apart.
 */
final class Color
{
    public static function darken(string $hex, int $percent = 12): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#' . $hex;
        }

        $factor = (100 - max(0, min(100, $percent))) / 100;

        return sprintf(
            '#%02x%02x%02x',
            (int) round(hexdec(substr($hex, 0, 2)) * $factor),
            (int) round(hexdec(substr($hex, 2, 2)) * $factor),
            (int) round(hexdec(substr($hex, 4, 2)) * $factor),
        );
    }

    public static function isValidHex(?string $hex): bool
    {
        return is_string($hex) && (bool) preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex);
    }
}
