<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * Poppins and Cairo, served by this shop instead of by Google.
 *
 * ── WHAT THIS IS FIXING, MEASURED RATHER THAN ASSERTED ──────────────────────
 *
 * PageSpeed Insights on extrabeauty.ae, 29 September 2026. The mobile run's
 * "Network dependency tree" is the whole argument:
 *
 *     Initial Navigation  https://extrabeauty.ae                 2,366 ms
 *       /css2?family=Poppins… (fonts.googleapis.com)             2,364 ms
 *         …v24/pxiEyp8kv….woff2 (fonts.gstatic.com)              4,366 ms
 *         …v24/pxiByp8kv….woff2 (fonts.gstatic.com)              4,367 ms
 *         …v24/pxiByp8kv….woff2 (fonts.gstatic.com)              4,368 ms
 *         …v24/pxiByp8kv….woff2 (fonts.gstatic.com)              4,369 ms
 *
 *     Maximum critical path latency: 4,369 ms
 *
 * Two hops to two different third-party origins before the first glyph exists,
 * and the <link> is render-blocking besides — Lighthouse charged it 750 ms of
 * the mobile "Render-blocking requests" total on its own. With
 * `font-display: swap` every word on the page is painted twice: once in the
 * system fallback at FCP (2.7 s) and again in Poppins at 4.4 s. Speed Index
 * integrates how much of the viewport is finished over time, so a whole-page
 * text repaint at 4.4 s is most of why that run's Speed Index was 7.7 s
 * against an LCP of 2.9 s — the shape the brief asked about: "the page keeps
 * changing visually long after the main content arrives".
 *
 * Served from this origin the chain is one hop, on a connection the document
 * already opened, discovered while the browser is still parsing <head>
 * because the faces are inline and the needed subset is preloaded.
 *
 * ── IT IS THE SAME FONT, BYTE FOR BYTE ──────────────────────────────────────
 *
 * These are Google's own files, fetched from fonts.gstatic.com and committed
 * unchanged — not a re-subset, not a re-compression. Every face css2 returns
 * is here, including the four devanagari Poppins faces this shop has no text
 * for, so `unicode-range` resolves to exactly the same face for exactly the
 * same codepoint it resolves to today. Nothing renders differently; the same
 * bytes arrive from a different host. Both families are under the SIL Open
 * Font Licence 1.1, which permits redistribution.
 *
 * Shipping the devanagari files costs the repository 157 KB and a shopper
 * nothing at all: `unicode-range` means a browser fetches a face only when a
 * codepoint in its range is actually on the page. Dropping them would have
 * been 157 KB cheaper and would have made "nothing renders differently" a
 * claim with an exception in it, which is not what rule 1 asks for.
 *
 * CAIRO IS A VARIABLE FONT AND ITS FOUR WEIGHTS ARE ONE FILE PER SUBSET. The
 * store layout already recorded that measurement — "Cairo:wght@400;600;700 and
 * the same list with 800 return THE SAME variable WOFF2 — same URL, same
 * SHA-256, 30,896 bytes either way" — and `cairo-arabic.woff2` here is 30,896
 * bytes, which is how that note was confirmed rather than trusted. Twelve
 * `@font-face` rules, three files.
 *
 * ── WHY THE FACES ARE INLINE AND NOT A STYLESHEET ───────────────────────────
 *
 * A `@vite('…/kbb-fonts.css')` entry would be correct and would cost one more
 * render-blocking round trip — the same shape of defect, moved to this origin.
 * Inline, the `@font-face` block is part of the document, so it is parsed
 * before the first stylesheet has been asked for, and the `<link rel=preload>`
 * beside it start the downloads in the same breath.
 *
 * ── EVERY URL IN IT COMES FROM THE MANIFEST, NOT FROM A STRING ──────────────
 *
 * `Vite::asset()` resolves each file to its hashed build path, so the fonts
 * can be cached for a year and still change the day they are rebuilt. It also
 * means the files are vite INPUTS (vite.config.js) rather than loose files
 * under public/build — `npx vite build` empties that directory, so a font
 * copied there by hand survives exactly until the next asset build.
 *
 * NOTHING AN OPERATOR CAN TYPE REACHES THE OUTPUT. The tables below are
 * constants; the only values printed unescaped are their own literals and a
 * path the manifest produced. Rule 5: "anything printed unescaped is a
 * constant, never a setting".
 */
final class WebFonts
{
    public const POPPINS = 'Poppins';

    public const CAIRO = 'Cairo';

    /**
     * The weights that are SERVED but never PRELOADED.
     *
     * preloadTags() emits one `<link rel=preload>` per face of the preload
     * subset, so adding a weight silently adds a preload -- and a preload is
     * critical-path bandwidth taken from the LCP image, which is the exact cost
     * the note on DIRS below says the preload list was chosen to avoid.
     *
     * 500 is on prices, filter chips and small labels. None of them is the LCP
     * element, so the face is fetched when a 500 glyph is first needed -- after
     * first paint -- and the critical path stays byte-for-byte what Lane PERF
     * measured it down to. Four preloads before this change and four after it;
     * PerfDeliveryTest counts them.
     *
     * @var list<int>
     */
    private const NO_PRELOAD_WEIGHTS = [500];

    /**
     * Where each family's files live under resources/, and which subset is
     * worth a preload.
     *
     * The preload subset is not a guess: for Poppins it is the four `latin`
     * files the owner's own report shows the browser fetching (8.42–8.60 KiB
     * each), and for Cairo it is `arabic`, which is the entire reason that
     * face is linked at all — the storefront's Latin text is Poppins on an
     * Arabic page too, because the stack appends Cairo rather than replacing
     * Poppins. A preload for a face no codepoint on the page needs is
     * bandwidth taken from the LCP image, and Chrome logs it as unused.
     */
    private const DIRS = [
        self::POPPINS => ['dir' => 'resources/fonts/poppins/', 'preload' => 'latin'],
        self::CAIRO => ['dir' => 'resources/fonts/cairo/', 'preload' => 'arabic'],
    ];

    /**
     * Exactly what fonts.googleapis.com/css2 returned on 29 September 2026 for
     * `family=Poppins:wght@400;500;600;700;800&display=swap`, transcribed
     * rather than retyped: the subset names, the weights and the unicode-ranges
     * are Google's.
     *
     * ── 500 ARRIVED LATER, AND IT IS A FIX RATHER THAN AN ADDITION ──────────
     *
     * This shop asks for weight 500 in 57 rules across its STOREFRONT
     * stylesheets -- eight in kbb.css, 22 in kbb-shop.css, 13 in
     * kbb-product.css, and the rest over cart, checkout, the grid skins and the
     * review block -- in three more in an admin preview, and in three each in
     * the journal, an article and the skin quiz. It had no 500 face to serve
     * any of them. Counted in Chromium, on the rendered page: 47 elements on
     * /shop/ compute to font-weight 500 (29 of them visible), 34 on a product
     * page (31 visible), 29 on the home page (27 visible), and 106 visible
     * across the eight pages measured.
     *
     * AND THE BROWSER DOES NOT SYNTHESISE IT. There is synthetic BOLD, and
     * there is no synthetic medium: CSS font matching for a target of 500 tries
     * 500, then weights BELOW it in descending order, and only then above -- so
     * with 400 and 600 present it picks 400 OUTRIGHT, and the text is the
     * regular face with no emboldening of any kind.
     *
     * Measured twice, because the first attempt at this measurement was wrong
     * and shipped wrong numbers in this very comment. A ruler string set in
     * `Poppins, system-ui, sans-serif` reports the SYSTEM font's widths on any
     * page where Poppins is absent, and reports them identically on every such
     * page -- which is what "the same number on all eight pages" meant and
     * nobody read. The honest instrument sets the ruler in `Poppins` ALONE and
     * measures a second ruler in a family that cannot exist; equal widths mean
     * Poppins never rendered. Both numbers below are from that instrument, on a
     * 40px ruler reading `Hydrating Serum AED 149`:
     *
     *   target weight      400      500      600      700      800
     *   before (no 500)  497.20   497.20   510.17   516.17   521.25
     *   after            497.20   505.00   510.17   516.17   521.25
     *
     * 500 sat EXACTLY on 400 to the hundredth of a pixel, which is the proof
     * that it was the 400 face and not a near miss. Every "medium" label,
     * price and filter chip on this shop rendered as regular from the day the
     * faces were self-hosted until this one.
     *
     * WHAT IT COSTS: 7,748 bytes for the latin file -- SMALLER than the 400
     * (7,884) and the 600 (8,000) already shipped -- fetched once, same origin,
     * `display:swap`, and NOT preloaded (see NO_PRELOAD_WEIGHTS). So it is not
     * on the critical path Lane PERF cut from 4,369 ms, and the preload count
     * is four before this change and four after it.
     *
     * AND THE THREE FILES ARE GOOGLE'S OWN, not a re-export: each was fetched
     * from the URL `css2` names and is sha256-IDENTICAL to it --
     * latin 7,748 bytes, latin-ext 5,484, devanagari 39,084, all three
     * byte-for-byte. `usWeightClass` reads 500 and the name table reads
     * "Poppins Medium", against "Poppins" at 400 and "Poppins SemiBold" at 600.
     * The unicode-ranges below were compared against the same response rather
     * than retyped.
     *
     * WEIGHT 300 IS NOT HERE, and was asked the same question rather than
     * skipped. store/skin-quiz was the only document that requested it. The
     * census found ZERO elements at font-weight 300 on seven of the eight pages
     * and exactly one on a product page, invisible (0x0). A target of 300 also
     * resolves to the 400 face -- 497.20px, the same number as 400 and 500 --
     * so the quiz was asking Google for a file that would have changed nothing.
     * It stops asking.
     *
     * @var list<array{subset: string, weight: int, file: string, range: string}>
     */
    private const POPPINS_FACES = [
            ['subset' => 'devanagari', 'weight' => 500, 'file' => 'poppins-devanagari-500.woff2',     'range' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09'],
            ['subset' => 'latin',      'weight' => 500, 'file' => 'poppins-latin-500.woff2',          'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 500, 'file' => 'poppins-latin-ext-500.woff2',      'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'devanagari', 'weight' => 400, 'file' => 'poppins-devanagari-400.woff2',  'range' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09'],
            ['subset' => 'latin',      'weight' => 400, 'file' => 'poppins-latin-400.woff2',       'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 400, 'file' => 'poppins-latin-ext-400.woff2',   'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'devanagari', 'weight' => 600, 'file' => 'poppins-devanagari-600.woff2',  'range' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09'],
            ['subset' => 'latin',      'weight' => 600, 'file' => 'poppins-latin-600.woff2',       'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 600, 'file' => 'poppins-latin-ext-600.woff2',   'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'devanagari', 'weight' => 700, 'file' => 'poppins-devanagari-700.woff2',  'range' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09'],
            ['subset' => 'latin',      'weight' => 700, 'file' => 'poppins-latin-700.woff2',       'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 700, 'file' => 'poppins-latin-ext-700.woff2',   'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'devanagari', 'weight' => 800, 'file' => 'poppins-devanagari-800.woff2',  'range' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09'],
            ['subset' => 'latin',      'weight' => 800, 'file' => 'poppins-latin-800.woff2',       'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 800, 'file' => 'poppins-latin-ext-800.woff2',   'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
    ];

    /**
     * The same, for `family=Cairo:wght@400;600;700;800&display=swap`. Three
     * distinct files across twelve rules; see the class note.
     *
     * @var list<array{subset: string, weight: int, file: string, range: string}>
     */
    private const CAIRO_FACES = [
            ['subset' => 'arabic',     'weight' => 400, 'file' => 'cairo-arabic.woff2',            'range' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC4, U+10EFC-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1'],
            ['subset' => 'latin',      'weight' => 400, 'file' => 'cairo-latin.woff2',             'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 400, 'file' => 'cairo-latin-ext.woff2',         'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'arabic',     'weight' => 600, 'file' => 'cairo-arabic.woff2',            'range' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC4, U+10EFC-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1'],
            ['subset' => 'latin',      'weight' => 600, 'file' => 'cairo-latin.woff2',             'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 600, 'file' => 'cairo-latin-ext.woff2',         'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'arabic',     'weight' => 700, 'file' => 'cairo-arabic.woff2',            'range' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC4, U+10EFC-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1'],
            ['subset' => 'latin',      'weight' => 700, 'file' => 'cairo-latin.woff2',             'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 700, 'file' => 'cairo-latin-ext.woff2',         'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'arabic',     'weight' => 800, 'file' => 'cairo-arabic.woff2',            'range' => 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC, U+102E0-102FB, U+10E60-10E7E, U+10EC2-10EC4, U+10EFC-10EFF, U+1EE00-1EE03, U+1EE05-1EE1F, U+1EE21-1EE22, U+1EE24, U+1EE27, U+1EE29-1EE32, U+1EE34-1EE37, U+1EE39, U+1EE3B, U+1EE42, U+1EE47, U+1EE49, U+1EE4B, U+1EE4D-1EE4F, U+1EE51-1EE52, U+1EE54, U+1EE57, U+1EE59, U+1EE5B, U+1EE5D, U+1EE5F, U+1EE61-1EE62, U+1EE64, U+1EE67-1EE6A, U+1EE6C-1EE72, U+1EE74-1EE77, U+1EE79-1EE7C, U+1EE7E, U+1EE80-1EE89, U+1EE8B-1EE9B, U+1EEA1-1EEA3, U+1EEA5-1EEA9, U+1EEAB-1EEBB, U+1EEF0-1EEF1'],
            ['subset' => 'latin',      'weight' => 800, 'file' => 'cairo-latin.woff2',             'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 800, 'file' => 'cairo-latin-ext.woff2',         'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
    ];

    /** @return list<array{subset: string, weight: int, file: string, range: string}> */
    public static function faces(string $family): array
    {
        return $family === self::CAIRO ? self::CAIRO_FACES : self::POPPINS_FACES;
    }

    /**
     * The `@font-face` rules for one family, for a <style> block in <head>.
     *
     * Written with no spaces and no newlines, because it is inline in every
     * page of the shop and "Minify CSS" is an audit in the same report this
     * class exists to answer.
     */
    public static function faceCss(string $family): string
    {
        $out = '';

        foreach (self::faces($family) as $face) {
            $out .= "@font-face{font-family:'".$family."';font-style:normal;font-weight:"
                .$face['weight']
                .';font-display:swap;src:url('
                .self::url($family, $face['file'])
                .") format('woff2');unicode-range:"
                .$face['range'].'}';
        }

        return $out;
    }

    /**
     * One `<link rel="preload">` per DISTINCT file in the preload subset.
     *
     * Distinct, because Cairo's four weights are one file: four identical
     * preloads would be three wasted lines of markup and a browser console
     * warning, not four downloads.
     *
     * `crossorigin` is REQUIRED and is not decoration. A font is fetched in
     * CORS mode whatever origin it is on, and a preload whose mode does not
     * match the fetch that follows is not reused — the browser downloads the
     * file twice and Chrome warns that the preloaded resource was unused. Same
     * origin needs no header, but the attribute has to be there.
     */
    public static function preloadTags(string $family): string
    {
        $subset = self::DIRS[$family]['preload'];
        $out = '';
        $done = [];

        foreach (self::faces($family) as $face) {
            if ($face['subset'] !== $subset || isset($done[$face['file']])) {
                continue;
            }

            if (in_array($face['weight'], self::NO_PRELOAD_WEIGHTS, true)) {
                continue;
            }

            $done[$face['file']] = true;
            $out .= '<link rel="preload" as="font" type="font/woff2" crossorigin href="'
                .e(self::url($family, $face['file']))."\">\n";
        }

        return $out;
    }

    /**
     * Every face file's source path, for vite.config.js and for the tests that
     * pin the two against each other.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $out = [];

        foreach (array_keys(self::DIRS) as $family) {
            foreach (self::faces($family) as $face) {
                $path = self::DIRS[$family]['dir'].$face['file'];

                if (! in_array($path, $out, true)) {
                    $out[] = $path;
                }
            }
        }

        return $out;
    }

    private static function url(string $family, string $file): string
    {
        return Vite::asset(self::DIRS[$family]['dir'].$file);
    }
}
