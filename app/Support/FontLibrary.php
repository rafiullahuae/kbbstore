<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * The shop's font library: twenty-nine families, self-hosted.     (Lane FS)
 *
 * The owner, 4 October: "prepare a full list of fonts to include in our app,
 * so we can use as per need. but don't make heavy the overal app, i need super
 * light stuff, fully optimized without bugs."
 *
 * ── SERVED THE WAY OUTFIT IS SERVED, FOR THE REASON WebFonts GIVES ─────────
 *
 * App\Support\WebFonts carries the measurement: a Google-hosted face is two
 * hops to two third-party origins before the first glyph, and a whole-page
 * repaint at 4.4 s. Every file here is Google's own woff2, fetched ONCE from
 * fonts.gstatic.com by tools/fs-fetch-fonts.py and committed unchanged; the
 * shop never names a font origin but its own. Each family is under the SIL
 * Open Font Licence 1.1 — checked against google/fonts' ofl/<family>/OFL.txt
 * for all twenty-nine, Outfit and Cairo included — which permits exactly
 * this: redistribution and embedding, with the licence carried in each
 * file's own name table.
 *
 * ── LIGHT BY CONSTRUCTION ───────────────────────────────────────────────────
 *
 *   · LATIN ONLY, plus ARABIC for an Arabic family. latin-ext, cyrillic, greek
 *     and vietnamese are glyphs this shop does not print.
 *   · VARIABLE WHEREVER GOOGLE HAS ONE: one file per subset, every weight.
 *     Seven families publish no variable cut; they ship the weights a heading
 *     and its text need and no more (Poppins 400/600/700, the Arabic statics
 *     400/700, the four single-weight display and script faces 400).
 *   · NOTHING IS PRINTED FOR A FAMILY NOBODY CHOSE. faceCss() is called only
 *     for a family a setting names — SiteFonts for the shop's body and
 *     headings, SectionType for a homepage section — so a fresh install's
 *     <head> is byte-identical to the one before this class existed, and
 *     choosing one section font adds that one family's @font-face and nothing
 *     else. A font's FILE is fetched by the browser only when text on the page
 *     actually resolves to it; the @font-face alone costs no request.
 *   · ONE PRELOAD, AND ONLY FOR THE BODY FONT (SiteFonts::preloadTags()). A
 *     heading face is discovered from CSS on the same connection; a preload
 *     for it would be critical-path bandwidth taken from the LCP image.
 *
 * ── OUTFIT AND CAIRO ARE ENTRIES, NOT COPIES ────────────────────────────────
 *
 * Their files and faces stay where they were, in WebFonts. Outfit is printed
 * by the layout on every page, so its entry here prints nothing; Cairo is
 * printed by the layout on an Arabic page, so its entry prints WebFonts' own
 * rules only on a page that does not already have them.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Everything this class prints is a literal of the table below or a path the
 * Vite manifest produced. A setting selects a KEY; exists() is the gate every
 * reader passes it through, so no operator string reaches a stylesheet.
 */
final class FontLibrary
{
    /** The library key that is the shop's typeface today. */
    public const DEFAULT = 'outfit';

    public const DIR = 'resources/fonts/lib/';

    /** What each `kind` is called in a picker, in the order a picker groups them. */
    public const KINDS = ['sans' => 'Modern sans', 'serif' => 'Elegant serif & display', 'script' => 'Handwritten script', 'arabic' => 'Arabic'];

    /**
     * The fallback each kind trails behind, so text paints in something of the
     * same shape while the file is on its way (font-display:swap).
     */
    private const STACKS = [
        'sans' => ",system-ui,-apple-system,'Segoe UI',Roboto,sans-serif",
        'serif' => ",Georgia,'Times New Roman',serif",
        'script' => ",'Segoe Script',cursive",
        'arabic' => ",system-ui,sans-serif",
    ];

    /** Google's unicode-ranges, identical for every family that has the subset. */
    public const RANGES = [
        'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        'arabic' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC4, U+10EFC-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1',
    ];

    /**
     * key => [CSS family name, kind, licence, bytes in the repository,
     *         faces: [subset, css2's font-weight, file under DIR]].
     *
     * Transcribed by tools/fs-fetch-fonts.py from what css2 returned, not
     * retyped. A weight with a space is a variable axis ("100 900"); a single
     * number is a static cut. `bytes` is the sum of the family's files on
     * disk and FontLibraryTest holds it to the disk.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: list<array{0: string, 1: string, 2: string}>}>
     */
    public const FAMILIES = [
        'outfit' => ['Outfit', 'sans', 'OFL-1.1', 47100, []],
        'inter' => ['Inter', 'sans', 'OFL-1.1', 48256, [['latin', '100 900', 'inter/inter-latin.woff2']]],
        'dm-sans' => ['DM Sans', 'sans', 'OFL-1.1', 36932, [['latin', '100 900', 'dm-sans/dm-sans-latin.woff2']]],
        'manrope' => ['Manrope', 'sans', 'OFL-1.1', 24836, [['latin', '200 800', 'manrope/manrope-latin.woff2']]],
        'plus-jakarta-sans' => ['Plus Jakarta Sans', 'sans', 'OFL-1.1', 27348, [['latin', '200 800', 'plus-jakarta-sans/plus-jakarta-sans-latin.woff2']]],
        'montserrat' => ['Montserrat', 'sans', 'OFL-1.1', 37956, [['latin', '100 900', 'montserrat/montserrat-latin.woff2']]],
        'nunito-sans' => ['Nunito Sans', 'sans', 'OFL-1.1', 31076, [['latin', '200 900', 'nunito-sans/nunito-sans-latin.woff2']]],
        'work-sans' => ['Work Sans', 'sans', 'OFL-1.1', 50316, [['latin', '100 900', 'work-sans/work-sans-latin.woff2']]],
        'figtree' => ['Figtree', 'sans', 'OFL-1.1', 20156, [['latin', '300 900', 'figtree/figtree-latin.woff2']]],
        'raleway' => ['Raleway', 'sans', 'OFL-1.1', 48264, [['latin', '100 900', 'raleway/raleway-latin.woff2']]],
        'poppins' => ['Poppins', 'sans', 'OFL-1.1', 23700, [['latin', '400', 'poppins/poppins-latin-400.woff2'], ['latin', '600', 'poppins/poppins-latin-600.woff2'], ['latin', '700', 'poppins/poppins-latin-700.woff2']]],
        'playfair-display' => ['Playfair Display', 'serif', 'OFL-1.1', 38404, [['latin', '400 900', 'playfair-display/playfair-display-latin.woff2']]],
        'cormorant-garamond' => ['Cormorant Garamond', 'serif', 'OFL-1.1', 37640, [['latin', '300 700', 'cormorant-garamond/cormorant-garamond-latin.woff2']]],
        'lora' => ['Lora', 'serif', 'OFL-1.1', 37788, [['latin', '400 700', 'lora/lora-latin.woff2']]],
        'fraunces' => ['Fraunces', 'serif', 'OFL-1.1', 36620, [['latin', '100 900', 'fraunces/fraunces-latin.woff2']]],
        'dm-serif-display' => ['DM Serif Display', 'serif', 'OFL-1.1', 24744, [['latin', '400', 'dm-serif-display/dm-serif-display-latin-400.woff2']]],
        'libre-baskerville' => ['Libre Baskerville', 'serif', 'OFL-1.1', 33872, [['latin', '400 700', 'libre-baskerville/libre-baskerville-latin.woff2']]],
        'marcellus' => ['Marcellus', 'serif', 'OFL-1.1', 14552, [['latin', '400', 'marcellus/marcellus-latin-400.woff2']]],
        'bodoni-moda' => ['Bodoni Moda', 'serif', 'OFL-1.1', 25884, [['latin', '400 900', 'bodoni-moda/bodoni-moda-latin.woff2']]],
        'eb-garamond' => ['EB Garamond', 'serif', 'OFL-1.1', 44336, [['latin', '400 800', 'eb-garamond/eb-garamond-latin.woff2']]],
        'great-vibes' => ['Great Vibes', 'script', 'OFL-1.1', 42800, [['latin', '400', 'great-vibes/great-vibes-latin-400.woff2']]],
        'dancing-script' => ['Dancing Script', 'script', 'OFL-1.1', 42708, [['latin', '400 700', 'dancing-script/dancing-script-latin.woff2']]],
        'allura' => ['Allura', 'script', 'OFL-1.1', 26488, [['latin', '400', 'allura/allura-latin-400.woff2']]],
        'tajawal' => ['Tajawal', 'arabic', 'OFL-1.1', 38208, [['arabic', '400', 'tajawal/tajawal-arabic-400.woff2'], ['latin', '400', 'tajawal/tajawal-latin-400.woff2'], ['arabic', '700', 'tajawal/tajawal-arabic-700.woff2'], ['latin', '700', 'tajawal/tajawal-latin-700.woff2']]],
        'almarai' => ['Almarai', 'arabic', 'OFL-1.1', 99444, [['arabic', '400', 'almarai/almarai-arabic-400.woff2'], ['latin', '400', 'almarai/almarai-latin-400.woff2'], ['arabic', '700', 'almarai/almarai-arabic-700.woff2'], ['latin', '700', 'almarai/almarai-latin-700.woff2']]],
        'ibm-plex-sans-arabic' => ['IBM Plex Sans Arabic', 'arabic', 'OFL-1.1', 125796, [['arabic', '400', 'ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-400.woff2'], ['latin', '400', 'ibm-plex-sans-arabic/ibm-plex-sans-arabic-latin-400.woff2'], ['arabic', '700', 'ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-700.woff2'], ['latin', '700', 'ibm-plex-sans-arabic/ibm-plex-sans-arabic-latin-700.woff2']]],
        'noto-kufi-arabic' => ['Noto Kufi Arabic', 'arabic', 'OFL-1.1', 154412, [['arabic', '100 900', 'noto-kufi-arabic/noto-kufi-arabic-arabic.woff2'], ['latin', '100 900', 'noto-kufi-arabic/noto-kufi-arabic-latin.woff2']]],
        'readex-pro' => ['Readex Pro', 'arabic', 'OFL-1.1', 54292, [['arabic', '200 700', 'readex-pro/readex-pro-arabic.woff2'], ['latin', '200 700', 'readex-pro/readex-pro-latin.woff2']]],
        'cairo' => ['Cairo', 'arabic', 'OFL-1.1', 81364, []],
    ];

    /**
     * The picker's option set — key => "Family · kind" — as a CONSTANT, so a
     * ModuleSchema select can name it and cast against it (rule 5: "a select
     * stores one of its own options or the default"). FontLibraryTest pins it
     * to FAMILIES key for key.
     */
    public const LABELS = [
        'outfit' => 'Outfit · modern sans (the shop today)',
        'inter' => 'Inter · modern sans',
        'dm-sans' => 'DM Sans · modern sans',
        'manrope' => 'Manrope · modern sans',
        'plus-jakarta-sans' => 'Plus Jakarta Sans · modern sans',
        'montserrat' => 'Montserrat · modern sans',
        'nunito-sans' => 'Nunito Sans · modern sans',
        'work-sans' => 'Work Sans · modern sans',
        'figtree' => 'Figtree · modern sans',
        'raleway' => 'Raleway · modern sans',
        'poppins' => 'Poppins · modern sans',
        'playfair-display' => 'Playfair Display · elegant serif',
        'cormorant-garamond' => 'Cormorant Garamond · elegant serif',
        'lora' => 'Lora · elegant serif',
        'fraunces' => 'Fraunces · elegant serif',
        'dm-serif-display' => 'DM Serif Display · display serif',
        'libre-baskerville' => 'Libre Baskerville · elegant serif',
        'marcellus' => 'Marcellus · display serif',
        'bodoni-moda' => 'Bodoni Moda · display serif',
        'eb-garamond' => 'EB Garamond · elegant serif',
        'great-vibes' => 'Great Vibes · script',
        'dancing-script' => 'Dancing Script · script',
        'allura' => 'Allura · script',
        'tajawal' => 'Tajawal · Arabic',
        'almarai' => 'Almarai · Arabic',
        'ibm-plex-sans-arabic' => 'IBM Plex Sans Arabic · Arabic',
        'noto-kufi-arabic' => 'Noto Kufi Arabic · Arabic',
        'readex-pro' => 'Readex Pro · Arabic',
        'cairo' => 'Cairo · Arabic (the shop’s Arabic today)',
    ];

    public static function exists(mixed $key): bool
    {
        return is_string($key) && isset(self::FAMILIES[$key]);
    }

    /** The CSS font-family value for a key: the family, then its kind's fallback. */
    public static function stack(string $key): string
    {
        [$family, $kind] = self::FAMILIES[$key];

        return "'".$family."'".self::STACKS[$kind];
    }

    /**
     * The `@font-face` rules for one family — '' for Outfit, which the layout
     * always prints, and for Cairo on a page whose layout already printed it.
     *
     * ▲ A FAMILY WITH ONE STATIC FILE IS DECLARED ACROSS 100–900. DM Serif
     * Display, Marcellus, Great Vibes and Allura exist at 400 only, and every
     * homepage heading asks for 700. Declared at 400, the browser would match
     * the face and then SMEAR a synthetic bold over it — the thick, blurred
     * script nobody chose. Declared across the range, the face satisfies 700
     * as it is drawn. Families with several static cuts keep their real
     * weights, so 600 and 800 resolve to the nearest real cut.
     */
    public static function faceCss(string $key, bool $arabicPage = false): string
    {
        if (! self::exists($key) || $key === self::DEFAULT) {
            return '';
        }

        if ($key === 'cairo') {
            return $arabicPage ? '' : WebFonts::faceCss(WebFonts::CAIRO);
        }

        [$family, , , , $faces] = self::FAMILIES[$key];
        $single = count($faces) === count(array_unique(array_column($faces, 0)));
        $out = '';

        /*
         * A FILE MISSING FROM THE BUILD COSTS THE FONT, NOT THE PAGE.
         * Vite::asset() throws for a path the manifest does not list — a
         * package applied without its public/build half would otherwise turn
         * one chosen heading font into a 500 on the homepage. The text then
         * falls back down its own stack, which is the outcome a font that
         * failed to download has anyway. FontLibraryTest holds the manifest
         * to the table, so this is the net under the net.
         */
        try {
            foreach ($faces as [$subset, $weight, $file]) {
                $out .= "@font-face{font-family:'".$family."';font-style:normal;font-weight:"
                    .($single && ! str_contains($weight, ' ') ? '100 900' : $weight)
                    .';font-display:swap;src:url('.self::url($file).") format('woff2');unicode-range:"
                    .self::RANGES[$subset].'}';
            }
        } catch (\Throwable) {
            return '';
        }

        return $out;
    }

    /**
     * One `<link rel=preload>` for the family's LATIN file at 400 — what the
     * body text of an English page resolves to. `crossorigin` is required; see
     * WebFonts::preloadTags() for why.
     */
    public static function preloadTag(string $key): string
    {
        if ($key === self::DEFAULT) {
            return WebFonts::preloadTags(WebFonts::OUTFIT);
        }

        try {
            $file = self::latinFile($key);
        } catch (\Throwable) {
            return '';
        }

        return $file === null ? '' : '<link rel="preload" as="font" type="font/woff2" crossorigin href="'.e($file)."\">\n";
    }

    /**
     * key => [label, kind, CSS stack, latin file URL] for the admin's picker,
     * which loads a face only when its option is on screen. Admin only; the
     * shop never receives this list.
     *
     * @return list<array{key: string, label: string, kind: string, stack: string, url: string}>
     */
    public static function catalogue(): array
    {
        $out = [];

        foreach (self::FAMILIES as $key => [$family, $kind]) {
            $out[] = ['key' => $key, 'label' => self::LABELS[$key], 'family' => $family, 'kind' => $kind,
                'stack' => self::stack($key), 'url' => (string) rescue(fn () => self::latinFile($key), '', false)];
        }

        return $out;
    }

    /**
     * Every library file's source path, for the tests that hold the table to
     * the disk and to the build manifest.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $out = [];

        foreach (self::FAMILIES as [, , , , $faces]) {
            foreach ($faces as [, , $file]) {
                $out[] = self::DIR.$file;
            }
        }

        return $out;
    }

    private static function latinFile(string $key): ?string
    {
        if ($key === 'cairo') {
            return Vite::asset('resources/fonts/cairo/cairo-latin.woff2');
        }

        if ($key === self::DEFAULT) {
            return Vite::asset('resources/fonts/outfit/outfit-latin.woff2');
        }

        foreach (self::FAMILIES[$key][4] ?? [] as [$subset, $weight, $file]) {
            if ($subset === 'latin' && (str_contains($weight, ' ') || $weight === '400')) {
                return self::url($file);
            }
        }

        return null;
    }

    private static function url(string $file): string
    {
        return Vite::asset(self::DIR.$file);
    }
}
