<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The lotus lockup the owner chose — logo option D, "Pearl" (Lane LG2).
 *
 * The owner: "option D is fine, but make sure it's crisp clear", "make sure no
 * logo should cut in mobile header ... we can reduce the logo size, it's okay,
 * also give control of size, outer spacing", and "the animation etc, must not
 * effect the site speed in any case". Reference build he approved:
 * docs/logo-options/D-crisp-live.html.
 *
 * Two jobs live here, both pure: the ARTWORK (a constant, so the only markup
 * printed unescaped is a string in this file) and the FIT ARITHMETIC that
 * keeps the lockup inside a phone header without clipping a glyph.
 *
 * ── HOW IT FITS WITHOUT JAVASCRIPT ──────────────────────────────────────────
 *
 * On a phone the logo link is `flex:1` between the menu button and the icons,
 * so its width IS the room the row leaves it. kbb.css makes it an inline-size
 * container and sizes everything inside it in `cqi`, from three numbers this
 * class works out from the settings and the words:
 *
 *   r   from this many px of room up, the name is drawn at its set size with
 *       its full letter-spacing (.085em)
 *   c1  below r, the letter-spacing tightens to .04em and the name, icon and
 *       gap shrink together at c1 cqi per px of their set size, so the name
 *       stays on ONE line at any width (the owner: "the logo must not come in
 *       two lines. it should come in one line always in any case")
 *   wf  the tagline, which on a phone runs beneath the whole lockup, is
 *       drawn from this many px of room up at its full size and steps out
 *       below it; the one-line name always has priority
 *
 * The widths come from Outfit's own advances (ADVANCE, measured in Chromium
 * at 100px), so a name the owner retypes is fitted as well as the default one.
 */
final class LogoLockup
{
    /**
     * Outfit capitals and the few marks a shop name uses, em per glyph at
     * wght 650 (the 600 cut is at most 1% narrower; the larger is kept, so the
     * estimate errs wide). Measured: 20 repeats of each glyph at 100px in the
     * self-hosted variable file, divided back down.
     */
    private const ADVANCE = [
        'A' => .686, 'B' => .636, 'C' => .662, 'D' => .751, 'E' => .608, 'F' => .584, 'G' => .790,
        'H' => .726, 'I' => .286, 'J' => .482, 'K' => .679, 'L' => .558, 'M' => .854, 'N' => .732,
        'O' => .812, 'P' => .616, 'Q' => .820, 'R' => .633, 'S' => .559, 'T' => .607, 'U' => .699,
        'V' => .677, 'W' => .971, 'X' => .668, 'Y' => .644, 'Z' => .577,
        '0' => .663, '1' => .372, '2' => .558, '3' => .554, '4' => .596, '5' => .556, '6' => .570,
        '7' => .515, '8' => .559, '9' => .570,
        '-' => .462, '&' => .685, "'" => .248, '.' => .297, ',' => .281, ' ' => .195, '/' => .393, '+' => .559,
    ];

    /** Anything not in the table (Arabic, accents): wider than any capital but M and W. */
    private const ADVANCE_OTHER = .76;

    /** Letter-spacing, em: the name everywhere, the tagline on a computer and on a phone. */
    public const TRACK_NAME = .085;

    /** The name's letter-spacing on a phone too narrow for the full one. */
    public const TRACK_NAME_TIGHT = .04;

    public const TRACK_TAG = .1;

    public const TRACK_TAG_PHONE = .04;

    /** The space between "K-BEAUTY" and "BLISS", em of the name. */
    public const WORD_GAP = .24;

    /** On a phone the tagline's rules may shrink to nothing; their .4em gaps stay. */
    private const TAG_GAPS_PHONE = 2 * .4;

    /** The icon's viewBox, 780 x 582. */
    public const ICON_RATIO = 780 / 582;

    /** "Auto" icon height, as a multiple of the name size — the reference's 38px at 22px. */
    public const ICON_AUTO = 1.72;

    /** Headroom on every fit: sub-pixel rounding and kerning never decide a line break. */
    private const SAFETY = 1.05;

    /**
     * The artwork. Constant: printed with {!! !!} in partials/logo-lockup, and
     * nothing a setting holds ever reaches it. `.lgx-o2` is the brighter copy
     * of the outlines the petal glow fades in and out; it is only printed while
     * that glow is on, so a shop without it pays nothing for it.
     */
    public const SVG_OPEN = '<svg class="lgx-i" viewBox="0 0 780 582" aria-hidden="true" focusable="false">';

    public const SVG_FILL = '<path class="lgx-f" d="M154 103Q399 122 439 424Q194 343 154 103ZM521 2Q407 136 471 389Q640 185 521 2ZM548 387Q642 239 778 249Q738 393 548 387ZM2 481Q190 313 434 475Q189 595 2 481ZM534 456Q707 461 737 544Q613 589 534 456Z"/>';

    public const OUTLINE = 'M445 438Q149 329 131 156Q353 165 394 377M494 329Q598 173 457 17Q362 185 483 408M552 386Q565 243 721 215Q730 331 519 434M397 473Q197 351 27 532Q196 629 460 479M487 466Q643 545 720 478Q612 397 532 473';

    /** The id the header's copy carries and the drawer's copy points at. */
    public const ART_ID = 'lgx-art';

    /**
     * The icon, in one of three shapes:
     *
     *   'def'   the site header: the paths, in a group the drawer can name
     *   'use'   the mobile drawer: `<use>` of the header's group, ~600 bytes
     *           lighter. The drawer is only ever opened from the header's own
     *           menu button, so wherever it can be seen the group exists.
     *   'full'  everywhere else (the checkout's header): the paths, no id, so
     *           no page can ever carry the id twice
     */
    public static function svg(bool $glow, string $art = 'full'): string
    {
        if ($art === 'use') {
            return self::SVG_OPEN.'<use href="#'.self::ART_ID.'"/></svg>';
        }

        $paths = self::SVG_FILL
            .'<path class="lgx-o" d="'.self::OUTLINE.'"/>'
            .($glow ? '<path class="lgx-o2" d="'.self::OUTLINE.'"/>' : '');

        return self::SVG_OPEN.($art === 'def' ? '<g id="'.self::ART_ID.'">'.$paths.'</g>' : $paths).'</svg>';
    }

    /** Width of a run of capitals, in em, including its letter-spacing. */
    public static function em(string $text, float $track): float
    {
        $w = 0.0;

        foreach (mb_str_split(mb_strtoupper($text)) as $ch) {
            $w += (self::ADVANCE[$ch] ?? self::ADVANCE_OTHER) + $track;
        }

        return $w;
    }

    /** The icon's height in whole pixels: the set number, or "auto" from the name. */
    public static function iconPx(int $set, int $name): int
    {
        return $set > 0 ? $set : (int) round($name * self::ICON_AUTO);
    }

    /**
     * The phone fit numbers (see the class note). Every input is an integer
     * the schema has already clamped; every output is a plain number.
     *
     * @return array{c1: float, r: float, wf: float}
     */
    public static function fit(string $name, string $accent, string $tagline, bool $tagOn, int $size, int $icon, int $gap, int $tag): array
    {
        $line = static fn (float $track): float => ($icon * self::ICON_RATIO + $gap
            + $size * (self::em($name, $track) + self::WORD_GAP + self::em($accent, $track))) * self::SAFETY;
        $tagW = $tagOn && $tagline !== '' ? $tag * (self::em($tagline, self::TRACK_TAG_PHONE) + self::TAG_GAPS_PHONE) : 0.0;

        return [
            'c1' => round(100 / $line(self::TRACK_NAME_TIGHT), 4),
            'r' => round($line(self::TRACK_NAME), 1),
            // Beneath the whole lockup, so it needs only its own width. No
            // tagline: a threshold no phone reaches, so it is never drawn.
            'wf' => $tagW > 0 ? round($tagW * self::SAFETY, 1) : 9999.0,
        ];
    }
}
