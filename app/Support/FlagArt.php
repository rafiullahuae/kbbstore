<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The two flags on the strip above the header, drawn as inline SVG.
 *
 * ── WHY NOT THE EMOJI, WHICH IS THE OBVIOUS ANSWER ──────────────────────────
 *
 * `🇦🇪` and `🇰🇷` are not characters. They are REGIONAL INDICATOR PAIRS —
 * U+1F1E6..U+1F1FF — and a flag appears only where the platform ships an
 * emoji font that happens to draw that pair. Windows does not: every version
 * of Chrome, Edge and Firefox on Windows renders the pair as the two boxed
 * letters `AE` and `KR`, because Segoe UI Emoji carries the indicators and no
 * flag glyphs. That is not a rare fallback — it is the default on the desktop
 * half of this shop's traffic, and this strip exists to say "authentic", so a
 * pair of letter-boxes is the one rendering that undoes it.
 *
 * The rest of the family is the same argument PaymentMarkArt makes at greater
 * length, and it holds here for the same three deployment reasons:
 *
 *   1. `bootstrap/app.php` ends with usePublicPath() pointing at a directory
 *      that is not the application root, so an <img src> to a file committed
 *      under public/ in this repo is a broken image on the server until
 *      somebody copies it by hand.
 *   2. Code reaches the server as a zip applied through Store → Core Updates.
 *      Markup inside a PHP string cannot arrive half-applied the way a package
 *      carrying a .php and two .svg files can — and packages 2.60.102–.106 are
 *      the reminder that half-applied is a real state on this host.
 *   3. An SVG sized in `em` follows the strip's own font size, so the flags
 *      scale with the wording from one control instead of two.
 *
 * ── AND WHY THEY ARE CONSTANTS ──────────────────────────────────────────────
 *
 * These are printed with `{!! !!}`. CLAUDE.md rule 5: anything printed
 * unescaped is a constant, never a setting. There is no interpolation in this
 * file, no setting read, no database read and no argument to either method —
 * the ONLY thing a shop can decide is whether the flags are drawn at all, and
 * that is a boolean on Appearance → Header → Flag bar.
 *
 * The accessible name is deliberately NOT in here. It is a translated string
 * and so a value, and injecting a value into markup that is then printed raw
 * is precisely the shape this rule forbids. partials/flag-bar.blade.php wraps
 * each drawing in `<span role="img" aria-label="{{ __(…) }}">` — escaped by
 * Blade, outside the constant — and the SVG itself is `aria-hidden`, so the
 * element has exactly one accessible name and it is a translatable one.
 *
 * ── THE DRAWINGS ────────────────────────────────────────────────────────────
 *
 * Both are geometry rather than artwork, so both are exact rather than
 * approximate. Each is a 3:2 viewBox, which is the official ratio of the UAE
 * flag and of the Korean one, so `height:Npx;width:auto` gives the same box.
 *
 * UAE: a red hoist band a quarter of the length, then equal green, white and
 * black bands. Colours are the government specification (#00732F, #FF0000).
 *
 * Korea: the taegeuk is a circle a third of the flag's length across — 20
 * units of 60, the specified diameter — made of two arcs of radius r/2 closed
 * by one of radius r,
 * which is the standard construction, rotated 33.69° so the red sits
 * upper-hoist. That
 * angle is not a rounding of anything: it is the angle of the flag's own
 * diagonal, atan(20/30) for a 3:2 field. The four trigrams sit on
 * that diagonal at the four corners with their bars square to it: geon (three
 * solid) upper hoist, gam upper fly, ri lower hoist, gon (three broken) lower
 * fly, which is the arrangement of the 1997 specification.
 */
final class FlagArt
{
    /** Flag of the United Arab Emirates. */
    public const UAE = '<svg viewBox="0 0 60 40" aria-hidden="true" focusable="false">'
        .'<rect width="60" height="13.34" fill="#00732F"/>'
        .'<rect y="13.33" width="60" height="13.34" fill="#FFFFFF"/>'
        .'<rect y="26.66" width="60" height="13.34" fill="#000000"/>'
        .'<rect width="15" height="40" fill="#FF0000"/>'
        .'</svg>';

    /** Flag of the Republic of Korea. */
    public const KOREA = '<svg viewBox="0 0 60 40" aria-hidden="true" focusable="false">'
        .'<rect width="60" height="40" fill="#FFFFFF"/>'
        .'<g transform="rotate(-33.69 30 20)">'
        .'<circle cx="30" cy="20" r="10" fill="#0047A0"/>'
        .'<path d="M20 20a5 5 0 0 1 10 0 5 5 0 0 0 10 0 10 10 0 0 0-20 0Z" fill="#CD2E3A"/>'
        .'</g>'
        .'<g fill="#000000">'
        // geon — upper hoist, three solid.
        .'<g transform="rotate(-56.31 12.5 8.4)">'
        .'<rect x="7" y="4.95" width="11" height="1.7"/>'
        .'<rect x="7" y="7.55" width="11" height="1.7"/>'
        .'<rect x="7" y="10.15" width="11" height="1.7"/>'
        .'</g>'
        // gam — upper fly, broken / solid / broken.
        .'<g transform="rotate(56.31 47.5 8.4)">'
        .'<rect x="42" y="4.95" width="4.6" height="1.7"/><rect x="48.4" y="4.95" width="4.6" height="1.7"/>'
        .'<rect x="42" y="7.55" width="11" height="1.7"/>'
        .'<rect x="42" y="10.15" width="4.6" height="1.7"/><rect x="48.4" y="10.15" width="4.6" height="1.7"/>'
        .'</g>'
        // ri — lower hoist, solid / broken / solid.
        .'<g transform="rotate(56.31 12.5 31.6)">'
        .'<rect x="7" y="28.15" width="11" height="1.7"/>'
        .'<rect x="7" y="30.75" width="4.6" height="1.7"/><rect x="13.4" y="30.75" width="4.6" height="1.7"/>'
        .'<rect x="7" y="33.35" width="11" height="1.7"/>'
        .'</g>'
        // gon — lower fly, three broken.
        .'<g transform="rotate(-56.31 47.5 31.6)">'
        .'<rect x="42" y="28.15" width="4.6" height="1.7"/><rect x="48.4" y="28.15" width="4.6" height="1.7"/>'
        .'<rect x="42" y="30.75" width="4.6" height="1.7"/><rect x="48.4" y="30.75" width="4.6" height="1.7"/>'
        .'<rect x="42" y="33.35" width="4.6" height="1.7"/><rect x="48.4" y="33.35" width="4.6" height="1.7"/>'
        .'</g>'
        .'</g>'
        .'</svg>';
}
