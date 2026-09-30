<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * Outfit and Cairo, served by this shop instead of by Google.
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
 * ── OUTFIT REPLACED POPPINS, AT THE OWNER'S WORD ────────────────────────────
 *
 * "can u plz match the font of overal site to 'Outfit'". A whole-shop typeface
 * change, done the way the self-hosting was done rather than by pointing at
 * Google again: these are Google's own files, fetched from fonts.gstatic.com
 * and committed unchanged, and no third-party font origin comes back. Outfit is
 * under the SIL Open Font Licence 1.1, which permits redistribution.
 *
 * OUTFIT IS A VARIABLE FONT AND THAT CHANGES THE ARITHMETIC. `css2` returns ten
 * rules and exactly TWO files — one per subset, every weight — the same shape
 * Cairo already had here. Measured, latin subset, which is the only one this
 * shop's text actually needs:
 *
 *     Poppins  five static files   7,884 + 7,748 + 8,000 + 7,816 + 7,824
 *                                  = 39,272 bytes, four of them preloaded
 *     Outfit   one variable file   32,292 bytes, all five weights, ONE preload
 *
 * So the shop ships 6,980 bytes less font and makes ONE request where it made
 * four. The preload count goes 4 → 1 and that is not a regression: the single
 * file is the whole latin subset, so the weight the LCP text needs is in the
 * first response rather than the fourth.
 *
 * ── AND DEVANAGARI GOES, WHICH IS THE ONE THING THAT IS NOT LIKE-FOR-LIKE ───
 *
 * Poppins publishes a devanagari subset and Outfit does not. The four Poppins
 * devanagari faces were shipped precisely so that "nothing renders differently"
 * had no exception in it, and that sentence cannot be said any more.
 *
 * WHAT IT COSTS, ASKED RATHER THAN ASSUMED: nothing that this repository can
 * see. A search for U+0900–U+097F across resources/views, resources/css and
 * database/ returns no match — there is no Devanagari text on this shop. If a
 * product name in the owner's own catalogue ever carries some, it renders in
 * the stack's next family (system-ui) instead of Poppins Devanagari, which is
 * what every other script on this shop already does. The latin and latin-ext
 * unicode-ranges are BYTE-IDENTICAL to Poppins's, so for every codepoint the
 * shop does have, a face resolves exactly as it did.
 *
 * The 157 KB of devanagari files leave the repository with them.
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
    public const OUTFIT = 'Outfit';

    public const CAIRO = 'Cairo';

    /**
     * The weights that are SERVED but never PRELOADED.
     *
     * preloadTags() emits one `<link rel=preload>` per face of the preload
     * subset, so adding a weight silently adds a preload -- and a preload is
     * critical-path bandwidth taken from the LCP image, which is the exact cost
     * the note on DIRS below says the preload list was chosen to avoid.
     *
     * ▲ AND AGAINST OUTFIT IT SELECTS NOTHING, WHICH IS WORTH SAYING RATHER
     * THAN LEAVING AS A CONSTANT THAT LOOKS LIKE IT IS DOING WORK.
     *
     * It was written for a family with one file per weight, where 500 — prices,
     * filter chips, small labels, none of them the LCP element — would have
     * been a fifth preload taking critical-path bandwidth from the LCP image.
     * Outfit is variable: its five weights are ONE latin file, so 400 preloads
     * that file and 500 arrives inside it at no extra cost. Skipping 500 here
     * removes nothing.
     *
     * It stays because preloadTags() serves any family in DIRS and the next
     * static one would need it again — and because deleting it would make the
     * preload count look like it fell from four to one for a reason nobody
     * wrote down. It fell because the files did. PerfDeliveryTest counts both.
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
        self::OUTFIT => ['dir' => 'resources/fonts/outfit/', 'preload' => 'latin'],
        self::CAIRO => ['dir' => 'resources/fonts/cairo/', 'preload' => 'arabic'],
    ];

    /**
     * Exactly what fonts.googleapis.com/css2 returned for
     * `family=Outfit:wght@400;500;600;700;800&display=swap`, transcribed rather
     * than retyped: the subset names, the weights and the unicode-ranges are
     * Google's, read out of the response with a script.
     *
     * TEN RULES, TWO FILES. Every weight of a subset resolves to the SAME url —
     * `QGYvz_MVcBeNP4NJtEtq.woff2` for latin at 400 and at 800 alike — which is
     * what a variable font looks like through css2, and the same shape Cairo
     * has here. Confirmed on the file rather than inferred from the URL:
     * `fvar` is present with a `wght` axis running 100 to 900.
     *
     * ── THE DISCRETE WEIGHTS ARE DELIBERATE, AND A RANGE WOULD HAVE BEEN A
     *    RENDERING CHANGE NOBODY ASKED FOR ────────────────────────────────────
     *
     * A variable face is more usually declared once per subset with
     * `font-weight:100 900`. That would make every weight between continuous —
     * and this shop has `font-weight:650` in two rules and `font-weight:300` in
     * one. Against the discrete list below, CSS font matching answers 650 with
     * the 700 face and 300 with the 400 face, which is exactly what static
     * Poppins answered. Against a range they would render as true 650 and true
     * 300: three rules changing appearance for a reason the owner did not ask
     * for. Ten rules, one file, and the weight ladder the shop already had.
     *
     * ── WHAT THE SHOP ACTUALLY ASKS FOR ────────────────────────────────────
     *
     * Counted across resources/css before anything was swapped: 700 in 194
     * rules, 600 in 155, 800 in 73, 500 in 60, 400 in 21 — plus the two 650s
     * and the one 300 above. All five served weights earn their place, and 500
     * is no longer a separate file to argue about.
     *
     * AND THE FILES ARE GOOGLE'S OWN, not a re-export: fetched from the URLs
     * css2 names, committed unchanged — latin 32,292 bytes, latin-ext 14,808.
     *
     * @var list<array{subset: string, weight: int, file: string, range: string}>
     */
    private const OUTFIT_FACES = [
            ['subset' => 'latin',      'weight' => 400, 'file' => 'outfit-latin.woff2',     'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 400, 'file' => 'outfit-latin-ext.woff2', 'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'latin',      'weight' => 500, 'file' => 'outfit-latin.woff2',     'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 500, 'file' => 'outfit-latin-ext.woff2', 'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'latin',      'weight' => 600, 'file' => 'outfit-latin.woff2',     'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 600, 'file' => 'outfit-latin-ext.woff2', 'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'latin',      'weight' => 700, 'file' => 'outfit-latin.woff2',     'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 700, 'file' => 'outfit-latin-ext.woff2', 'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
            ['subset' => 'latin',      'weight' => 800, 'file' => 'outfit-latin.woff2',     'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD'],
            ['subset' => 'latin-ext',  'weight' => 800, 'file' => 'outfit-latin-ext.woff2', 'range' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF'],
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
        return $family === self::CAIRO ? self::CAIRO_FACES : self::OUTFIT_FACES;
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
