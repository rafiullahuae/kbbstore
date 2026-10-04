<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lane NV — "Fit the menu to the row" (Appearance → Header → Navigation).
 *
 * The owner, 4 October: the desktop menu's text should grow into the empty
 * space at the end of the row, or shrink when there are more items, so the row
 * is filled edge to edge — and only once the menu has at least nine top-level
 * items. "less than that it will display as it is."
 *
 * ── WHAT THIS CLASS DECIDES, AND WHAT IT HANDS THE STYLESHEET ──────────────
 *
 * Whether the bar is fitted at all, and if it is, a handful of INTEGERS the
 * stylesheet turns into a first-paint font size with no script:
 *
 *   --nav-n    top-level items
 *   --nav-np   of those, the ones padded by --nav-pad-x (not a highlight pill)
 *   --nav-hl   highlight pills, whose own inline padding is 10px a side
 *   --nav-gx   gaps inside the links (one per ▾ and one per badge)
 *   --nav-bx   badges, each carrying 10px of its own padding
 *   --nav-w    the labels' estimated width, in hundredths of an em
 *   --nav-min / --nav-max   the clamp, in px, from two selects of fixed options
 *
 * kbb.css (`.mbar.nav-fill`) divides the bar's real content width, --hd-room,
 * less every fixed pixel above, by --nav-w. That is a length divided by a
 * number, which calc() can do on one render.
 *
 * ── WHY AN ESTIMATE IS ACCEPTABLE HERE WHEN nav-fit.js's HEADER REJECTS ONE ──
 *
 * That header rejected an estimate as the WHOLE answer: underestimate the words
 * by six pixels and the bar wraps. This one is only the FIRST PAINT. nav-fit.js
 * still measures the real boxes and sets --nav-scale, in both directions, on
 * load, on resize and when the webfont lands. The estimate's job is to make the
 * first paint nearly right, and it is deliberately generous so that the error
 * goes the safe way: a few per cent SMALL (which the script then grows, inside
 * a bar whose height is fixed, so nothing below it moves) rather than large
 * (which would wrap a row before the script ran).
 *
 * The weights were fitted against the shop's own twelve labels in Chromium at
 * 13px: Outfit 600 for English, Cairo for Arabic. Measured 60.2em / 67.5em of
 * label text; these weights say 64.4em / 70.9em — 7% and 5% generous.
 */
final class NavRowFit
{
    /** Width of one character, in hundredths of an em. */
    private const SPACE = 25;
    private const DIGIT = 56;
    private const UPPER = 64;
    private const LOWER = 52;
    private const ARABIC = 50;
    private const WIDE = 110;   // CJK, emoji and anything past U+2E80
    private const OTHER = 60;

    /** ▾ at .615em of the link's size, as a share of the link's em. */
    private const INDICATOR = 45;

    /** A badge's letters at .654em, weight 800, as a share of the link's em. */
    private const BADGE_CHAR = 45;

    /**
     * The `style` for `.mbar.nav-fill`, or null when the bar is not fitted —
     * in which case the partial prints exactly what it printed before.
     *
     * @param  list<array<string, mixed>>  $items  the top-level menu as the bar draws it
     * @param  array<string, mixed>  $settings  HeaderSettings::all()
     */
    public static function style(array $items, bool $mega, array $settings): ?string
    {
        if (! ($settings['nav_fit'] ?? false)) {
            return null;
        }

        $n = count($items);

        if ($n === 0 || $n < (int) ($settings['nav_fit_from'] ?? 9)) {
            return null;
        }

        $hl = 0;
        $gx = 0;
        $bx = 0;
        $w = 0;

        foreach ($items as $item) {
            $w += self::width((string) ($item['label'] ?? ''));

            if (! empty($item['highlight_color'])) {
                $hl++;
            }

            if (! empty($item['badge'])) {
                $bx++;
                $gx++;
                $w += self::BADGE_CHAR * mb_strlen(trim((string) $item['badge']));
            }

            if ($mega && ! empty($item['children'])) {
                $gx++;
                $w += self::INDICATOR;
            }
        }

        $min = (int) ($settings['nav_fit_min'] ?? 10);
        $max = (int) ($settings['nav_fit_max'] ?? 18);

        return implode(';', [
            '--nav-n:'.$n,
            '--nav-np:'.($n - $hl),
            '--nav-hl:'.$hl,
            '--nav-gx:'.$gx,
            '--nav-bx:'.$bx,
            '--nav-w:'.max(100, $w),
            '--nav-min:'.$min.'px',
            '--nav-max:'.max($min, $max).'px',
        ]);
    }

    /** A label's estimated width, in hundredths of an em. */
    public static function width(string $label): int
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $w = 0;

        foreach (mb_str_split($label) as $ch) {
            $cp = mb_ord($ch);

            $w += match (true) {
                $ch === ' ' => self::SPACE,
                $cp >= 0x30 && $cp <= 0x39 => self::DIGIT,
                $cp >= 0x41 && $cp <= 0x5A => self::UPPER,
                $cp >= 0x61 && $cp <= 0x7A => self::LOWER,
                $cp >= 0x0600 && $cp <= 0x06FF => self::ARABIC,
                $cp >= 0x2E80 => self::WIDE,
                default => self::OTHER,
            };
        }

        return $w;
    }
}
