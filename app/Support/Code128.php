<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Code 128 subset B, as bar widths. No dependency, no image, no shell.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * The dispatch label and the packing slip carry the order number as a machine-
 * readable code, so the bench can scan a parcel instead of reading nine
 * characters off a sheet and typing them into the admin. Every ordinary way of
 * producing one is closed on this host:
 *
 *   - a composer barcode library cannot reach the server. `vendor/` is in
 *     BuildPackage::NEVER_SHIP *and* in UpdateGuard::FORBIDDEN_PREFIXES, so a
 *     package carrying one is refused twice over;
 *   - a PNG needs GD or Imagick and a writable path under a web root that is a
 *     DIFFERENT directory from the application root on this host (CLAUDE.md) —
 *     the same arrangement that already makes public/build/ unreachable from a
 *     view;
 *   - a barcode FONT would be a webfont served from that same unreachable web
 *     root, and a print stylesheet whose font 404s prints the digits instead of
 *     the bars. Silently, and only on the live host.
 *
 * What is left is the geometry, which is all a barcode ever was: a run-length
 * list of black and white widths. This class returns that list; the Blade
 * partial draws it with elements whose widths are in millimetres, so the
 * printer's own resolution renders the bars rather than an image resampled to
 * fit.
 *
 * ── SUBSET B AND NOTHING ELSE ───────────────────────────────────────────────
 *
 * B covers ASCII 32–126, which is every character an order number in this shop
 * has ever contained (`KBB-10427`, `KBB-DOC-3`). No subset switching and no
 * numeric-pair compression in C: the saving would be a few millimetres on a
 * label that has room, and each extra mode is another branch that can emit a
 * code a scanner reads as a different string from the one printed underneath
 * it. A barcode that disagrees with its own human-readable text is worse than
 * no barcode, so this encodes one way and refuses everything else.
 *
 * encode() returns null rather than throwing for anything it cannot represent —
 * an empty string, an over-long one, or a character outside 32–126, which is
 * what an Arabic order number would be. The caller then prints the number as
 * text and the label stays a usable label. A document must not 500 because a
 * value was unusual.
 */
final class Code128
{
    /**
     * The 107 element-width patterns, indexed by code value.
     *
     * Each is bar, space, bar, space, bar, space in modules; 106 (stop) has a
     * seventh element, the extra terminating bar. This is the table from the
     * specification, not a computed one — it cannot be derived, and a
     * transcription error is why the test pins a hand-worked example rather
     * than only checking the shape of the output.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public const START_B = 104;

    public const STOP = 106;

    /**
     * The specification's quiet zone, in modules, on each side.
     *
     * Ten modules of white. A scanner that cannot see one reads nothing at all,
     * and that is the commonest single reason a printed barcode "does not
     * work". The partial reserves it as padding rather than leaving it to
     * whatever the surrounding layout happens to give.
     */
    public const QUIET_MODULES = 10;

    /**
     * The longest string this will encode.
     *
     * The label is 105mm wide with margins. At the 0.34mm module the partial
     * draws, 24 characters is already about 94mm of bars; past that the code
     * runs off the sheet and prints truncated — which still scans, and scans as
     * the wrong string. Refusing is the safe end of that.
     */
    public const MAX_LENGTH = 24;

    /**
     * Bar and space widths in modules, starting with a BAR and alternating.
     *
     * @return list<int>|null  null when $text cannot be encoded in subset B
     */
    public static function encode(string $text): ?array
    {
        if ($text === '' || strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        $values = [];

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $ord = ord($text[$i]);

            // Subset B is ASCII 32..126. A byte outside that is either control
            // input or one byte of a multi-byte character, and encoding half a
            // UTF-8 sequence would produce a code that scans as mojibake.
            if ($ord < 32 || $ord > 126) {
                return null;
            }

            $values[] = $ord - 32;
        }

        // Weighted modulo-103 check character. The start code counts once; each
        // data value counts by its 1-based position.
        $sum = self::START_B;

        foreach ($values as $position => $value) {
            $sum += ($position + 1) * $value;
        }

        $codes = array_merge([self::START_B], $values, [$sum % 103, self::STOP]);

        $widths = [];

        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $module) {
                $widths[] = (int) $module;
            }
        }

        return $widths;
    }

    /**
     * Total width in modules, quiet zones included.
     *
     * @param  list<int>  $widths
     */
    public static function modules(array $widths): int
    {
        return array_sum($widths) + (2 * self::QUIET_MODULES);
    }
}
