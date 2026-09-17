<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A signed number that keeps its sign on the left, in a right-to-left run.
 *
 * ── THE DEFECT, PHOTOGRAPHED BY T6 AND MEASURED HERE ────────────────────────
 *
 * `-30%` on the Arabic sale ribbon renders as `30%-`. It is not CSS and it is
 * not a broken font: HYPHEN-MINUS carries the bidi class ES, which is WEAK, so
 * the Unicode bidirectional algorithm resolves it from the surrounding run and
 * moves it to the trailing side of a right-to-left one. docs/rtl-audit.md
 * §11.10 recorded it and handed it to whoever formats the label.
 *
 * ── WHAT WAS MEASURED, IN CHROMIUM, BY READING THE PAINTED ORDER ────────────
 *
 * Every candidate was rendered and each character's box read back and sorted by
 * x, so these are the glyph positions the engine actually produced rather than
 * an opinion about the algorithm. Logical string `-30%`:
 *
 *                            <html dir=rtl>            <html dir=ltr>
 *                            alone   in Arabic text    alone   in Arabic text
 *   plain HYPHEN-MINUS       30%-    %30-              -30%    %30-
 *   U+2212 MINUS SIGN        30%−    %30−              −30%    %30−
 *   CSS unicode-bidi:isolate 30%-    30%-              -30%    -30%
 *   U+2066 … U+2069          -30%    -30%              -30%    -30%
 *
 * THREE THINGS THAT FOLLOW, and the first two contradict what was written down:
 *
 * 1. **U+2212 MINUS SIGN DOES NOT FIX IT.** §11.10 offers it as an alternative
 *    to the isolate. It is not one. U+2212 is bidi class ES exactly as
 *    HYPHEN-MINUS is, and it reorders identically — measured, both give `30%-`.
 *    Only its shape differs.
 * 2. **THE BUG IS NOT LIMITED TO THE MIRRORED LAYOUT.** In an `<html dir="ltr">`
 *    document, `-30%` sitting inside Arabic text still paints `%30-`, because an
 *    Arabic word opens a right-to-left run wherever it is. So this is gated on
 *    the LANGUAGE and never on Locale::isRtl() — the same conclusion the Arabic
 *    typeface reached, for a different reason and with its own measurement.
 * 3. **CSS `unicode-bidi: isolate` IS NOT ENOUGH.** It gives the number its own
 *    run but that run is still right-to-left, so the sign still trails.
 *    `unicode-bidi: plaintext` does work — and the storefront serves BUILT css,
 *    so a stylesheet fix ships inert until somebody rebuilds the bundle. This
 *    fix is in the string, where no build step can swallow it.
 *
 * ── WHY THE ENGLISH PAGE GETS NOTHING ───────────────────────────────────────
 *
 * Measured in the same run: in an English document with English text around it,
 * `-30%` already paints `-30%`, isolate or no isolate. The isolate would be a
 * no-op that changed the bytes of every English storefront page —
 * StorefrontEnglishUnchangedTest's contract — for no rendered difference. So
 * number() returns its argument untouched when the page is English, exactly as
 * the Arabic typeface block emits nothing there.
 *
 * The residual case this leaves is Arabic text on an ENGLISH page, which the
 * storefront does not serve: /ar is where Arabic copy lives.
 *
 * ── WRAP THE NUMBER, NEVER THE LABEL ────────────────────────────────────────
 *
 * The isolate forces its contents left-to-right. That is right for `-30%`,
 * `+2` and `-AED 25.00`, which have no strong character of their own, and WRONG
 * for a label that is genuinely Arabic: measured, `⁦خصم 30%⁩` paints
 * `30 مصخ%` — the isolate pulls an Arabic phrase into left-to-right order.
 * number() is therefore for the numeric token only. A whole label that may be
 * Arabic in one language and English in another wants `<bdi>`, whose default
 * dir=auto picks the direction from the label's own first strong character —
 * measured correct in both — and that is the recommendation recorded for
 * store.product_card.label_off in docs/FS-ARABIC-TYPOGRAPHY.md.
 */
final class Bidi
{
    /** U+2066 LEFT-TO-RIGHT ISOLATE. */
    public const LRI = "\u{2066}";

    /** U+2069 POP DIRECTIONAL ISOLATE. */
    public const PDI = "\u{2069}";

    /**
     * A numeric token — `-30%`, `+2`, `– AED 25.00` — that must read the same
     * way in an Arabic page as it does in an English one.
     *
     * Returns the token unchanged on an English page, and idempotent: a token
     * that is already isolated is not isolated twice.
     */
    public static function number(string $token): string
    {
        if ($token === '' || Locale::current() === Locale::DEFAULT) {
            return $token;
        }

        if (str_starts_with($token, self::LRI) && str_ends_with($token, self::PDI)) {
            return $token;
        }

        return self::LRI . $token . self::PDI;
    }
}
