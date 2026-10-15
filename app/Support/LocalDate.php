<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * A date as the page's language writes it (Lane AR round 2).
 *
 * Every shopper-facing date in the storefront was `->format('j M Y')`, which
 * prints English month names on /ar -- and, worse, an English date inside a
 * right-to-left line is reordered by the bidi algorithm: "12 Oct 2026" painted
 * as "Oct 2026 12" on every review card, "6 Oct 2026 · 3 منتجات" became
 * "Oct 2026 · 3 6 منتجات" on the orders list. The fix is not an isolate around
 * the English: it is the date in Arabic, "12 أكتوبر 2026", which reads in the
 * right order on its own.
 *
 * ENGLISH IS EXACTLY `$date->format($format)` -- same call, same bytes -- so a
 * view converted to this helper renders the English page byte-identically.
 * Arabic is Carbon's own `translatedFormat()` in the `ar` locale (the month
 * and day names UAE sites use: أكتوبر، السبت), Western digits kept, as the
 * rest of the shop prints them.
 */
final class LocalDate
{
    public static function format(?CarbonInterface $date, string $format, string $empty = ''): string
    {
        if ($date === null) {
            return $empty;
        }

        if (Locale::current() !== 'ar') {
            return $date->format($format);
        }

        // "D, j M" -> "السبت 17 أكتوبر": the full day name (the short Arabic
        // ones read as clipped words), no Latin comma.
        $format = str_replace(['D, ', 'D '], ['l ', 'l '], $format);

        return str_replace(', ', '، ', $date->copy()->locale('ar')->translatedFormat($format));
    }
}
