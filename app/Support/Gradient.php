<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic gradient placeholders for products with no image.
 *
 * Ported verbatim from the theme (kbb_grad / kbb_initials), crc32 seeding
 * included, so every product keeps the exact colour it has today. Changing the
 * algorithm would reshuffle every placeholder on the site — precisely the silent
 * visual drift the display-parity rule exists to prevent.
 */
final class Gradient
{
    private const PALETTE = [
        'linear-gradient(135deg,#ffe9a8,#f3c969)',
        'linear-gradient(135deg,#ffd1e2,#ff9fc1)',
        'linear-gradient(135deg,#bfe9d2,#7fd3a9)',
        'linear-gradient(135deg,#d9ccff,#b39cff)',
        'linear-gradient(135deg,#ffd9c9,#ff9f80)',
        'linear-gradient(135deg,#cfe6ff,#8fc0f0)',
    ];

    public static function for(string $seed): string
    {
        return self::PALETTE[abs(crc32($seed)) % count(self::PALETTE)];
    }

    /** Up to two initials: "Beauty of Joseon" -> "BO". */
    public static function initials(string $text): string
    {
        $parts = preg_split('/\s+/', trim(strip_tags($text))) ?: [];
        $out = '';

        foreach ($parts as $part) {
            if ($part !== '') {
                $out .= mb_substr($part, 0, 1);
            }
        }

        return mb_strtoupper(mb_substr($out, 0, 2));
    }
}
