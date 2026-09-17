<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The Arabic typeface, in the one place that knows how to ask for it.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * layouts/store.blade.php shipped Cairo twice over and got it wrong the first
 * time: Lane EP added the <link> and no font stack in the storefront ever named
 * the family, so the face was downloaded by nothing and every Arabic word still
 * rendered in the device fallback while the page paid for a 30 KB stylesheet
 * request (docs/rtl-audit.md §11.8). T6's manual half fixed that for the shared
 * layout by restating each stack with "Cairo" inserted after the Latin face.
 *
 * FIVE STOREFRONT PAGES DO NOT USE THAT LAYOUT. store/blog, store/post,
 * store/skin-quiz, store/app and store/review-wall each carry their own <html>,
 * their own <head>, their own inline stylesheet and their own webfont <link>,
 * and none of them got either half — measured in docs/rtl-standalone-documents.md
 * §2 and reproduced by this lane: /ar/skincare-guide/, /ar/<post>/,
 * /ar/skin-quiz/ and /ar/reviews/ linked Poppins only, /ar/app/ linked Fraunces
 * and Hanken Grotesk, and all five named Cairo zero times.
 *
 * Restating the same five-line block in five documents is how four of them end
 * up right and the fifth ends up gated on the wrong thing. So the gate, the
 * request and — above all — the APPEND RULE live here, and
 * resources/views/partials/arabic-face.blade.php is the only place that emits
 * them.
 *
 * ── THE TWO CONSTRAINTS, MADE STRUCTURAL RATHER THAN REMEMBERED ─────────────
 *
 * APPEND, NEVER SUBSTITUTE. Poppins, Hanken Grotesk and Fraunces have no Arabic
 * glyphs, and Cairo's Latin is not the brand face. Per-codepoint font selection
 * is exactly what is wanted: the Latin family keeps the wordmark, the prices and
 * every English word on a mixed page, and Cairo is reached only for the
 * codepoints the Latin face has no glyph for. append() therefore INSERTS rather
 * than replacing, and it inserts at position 1 — after the first family and
 * before the generic fallbacks — so a caller cannot put Cairo first by writing
 * the stack out differently. Callers pass the document's own Latin stack
 * verbatim; there is no spelling of the argument that substitutes.
 *
 * GATED ON THE LANGUAGE, NOT ON THE DIRECTION. This shop has two switches:
 * Locale::direction() answers 'ltr' for Arabic while the mirrored layout is
 * still being built, which is a state the owner can choose from the Translation
 * console. Arabic words need Arabic glyphs in that state too, so nothing here
 * ever consults Locale::isRtl(). The partial asks Locale::current().
 *
 * ── WEIGHTS COST NOTHING, AND ARE STILL NOT FREE ────────────────────────────
 *
 * Google serves Cairo as a VARIABLE font: every weight in the request maps to
 * the same arabic WOFF2. Re-measured by this lane against fonts.googleapis.com
 * with a Chrome user agent, one arabic file in each case —
 *
 *   Cairo:wght@400;500;600;700           6,951 B of CSS, SLXV…QyyS4J0.woff2, 30,896 B
 *   Cairo:wght@400;500;600;700;800       8,689 B of CSS, the same file, byte for byte
 *   Cairo:wght@300;400;500;600;700;800  10,427 B of CSS, the same file, byte for byte
 *
 * sha256 of the arabic file is 748022f50c427456… in all three, which is the same
 * file §10 of the audit weighed. So the ONLY thing a weight costs is stylesheet
 * bytes, on Arabic pages only — which is why each document asks for the weights
 * its own Latin link asks for rather than for one padded union list.
 */
final class ArabicFace
{
    /** Drawn as an Arabic face with a Latin companion; SIL OFL, so nothing has to be self-hosted. */
    public const FAMILY = 'Cairo';

    /** The Google Fonts stylesheet for a weight list, e.g. '400;500;600;700'. */
    public static function href(string $weights): string
    {
        if (preg_match('/^[1-9]00(?:;[1-9]00)*$/', $weights) !== 1) {
            throw new InvalidArgumentException("Cairo weight list must be hundreds separated by ';', got '{$weights}'.");
        }

        return 'https://fonts.googleapis.com/css2?family=' . self::FAMILY . ':wght@' . $weights . '&display=swap';
    }

    /**
     * The document's own Latin stack, with Cairo inserted after the first family.
     *
     * Idempotent: a stack that already names Cairo comes back untouched, so a
     * document that is given the shared layout later cannot end up with it
     * twice.
     */
    public static function append(string $stack): string
    {
        $families = array_map('trim', explode(',', $stack));

        if ($families === [] || $families[0] === '') {
            throw new InvalidArgumentException('Cannot append the Arabic face to an empty font stack.');
        }

        foreach ($families as $family) {
            if (strcasecmp(trim($family, " \t\"'"), self::FAMILY) === 0) {
                return $stack;
            }
        }

        array_splice($families, 1, 0, ['"' . self::FAMILY . '"']);

        return implode(',', $families);
    }
}
