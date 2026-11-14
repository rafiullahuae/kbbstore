<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;

/**
 * Appearance → Product page → Product gifts strip.              (Lane GS, GS2)
 *
 * The owner, 14 November 2026, after the confetti strip (Lane GS, design KL)
 * looked "like a temu fake / scam website" and he had seen the one-line
 * previews (docs/plans/product-gift-strip/soft.py):
 *
 *   "Free gifts with every order. Limited time only! use this text in one
 *    line, the text should adjust as per the screen size, must be in one line.
 *    flowing underline is fine as default, give other options to choose from +
 *    existing colored options we have. keep all options incase we needed later
 *    on. give me the patch"
 *
 * ── TWENTY-SEVEN STYLES, ONE ON THE PAGE ───────────────────────────────────
 *
 * STYLES holds every design he has seen — the six one-line versions (soft.py),
 * the plain line and the four calm cards (minimal.py) and the sixteen
 * colourful ones (preview.py, A–M with K, KA, KB, KL) — each as a CONSTANT CSS
 * string. The shop's render-blocking kbb-product.css carries none of them: the
 * strip prints BASE + its group + the ONE chosen style in a <style> beside
 * itself (css()), so a page pays for the design it shows and nothing else, and
 * a page with the strip switched off pays nothing. The admin sample is the
 * only place that loads them all (adminCss()).
 *
 * ── ONE LINE ON EVERY SCREEN, WITH CSS ONLY ─────────────────────────────────
 *
 * Both lines are drawn as ONE line (line 1 bold, a space, line 2 lighter),
 * nowrap. The server knows the words, so it prints their width in hundredths
 * of an em (`--pgs-w`, from Outfit's own advance widths — WIDTHS) and the CSS
 * sizes the type to the strip: min(his largest size, (the strip's width −
 * the icon) ÷ that width). The strip is its own size container; a browser
 * without container units gets the same sum against the viewport. Nothing
 * measures anything.
 *
 * ── RULE 5: NOTHING FROM A SETTING REACHES THE PAGE BUT INTEGERS AND HEX ───
 *
 *   css()     a constant of this file, picked by a key of STYLES.
 *   style()   `--pgs-…:<int>px`, `#RRGGBB`, one `rgba(<int>,<int>,<int>,.55)`
 *             built here from a validated hex, and the integer `--pgs-w`.
 *   classes() literal class names from this file and a select's own keys.
 *   words     through Blade's escaper (e()).
 *
 * ── AND IT COSTS NO QUERY ───────────────────────────────────────────────────
 *
 * Every value is a row of `settings`, read through the snapshot SettingsService
 * already holds for the request (SettingsRequestMemo); all() is memoised.
 */
class ProductGiftStrip
{
    public const PREFIX = 'pgs_';

    /** The laptop placements, key => label. Every one is a literal class name. */
    public const DESK = [
        'paylater' => 'Above Tabby & Tamara',
        'price' => 'Above the price',
        'cart' => 'Above Add to cart',
        'aftercart' => 'Below Add to cart',
        'off' => 'Not shown on laptops',
    ];

    /** The admin's option groups, in order: group key => label. */
    public const GROUPS = [
        'line' => 'One line',
        'card' => 'Calm cards',
        'col' => 'Colourful',
    ];

    /*
     * ── THE CSS ─────────────────────────────────────────────────────────────
     *
     * Every never-ending animation moves transform or opacity only, so it runs
     * on the compositor (ShopAnimationsCompositorOnlyTest reads these
     * constants). The previews' background-position drifts are drawn as a
     * wider layer moved by transform instead — the same pixels — anchored with
     * SYMMETRIC inline insets, so they need no physical left/right on /ar.
     * The two exceptions are the words with a shine clipped to their letters
     * (Rose shimmer words, Black & gold), which no transform can move inside
     * the glyphs; both are options, not the default.
     *
     * No newer CSS function beside var() outside @supports (the 2.60.492
     * landmine): container units and clamp() live inside
     * `@supports (font-size:1cqi)`, with plain px outside.
     *
     * Durations are `calc(Ns * var(--pgs-sp,1))` so Speed is one class.
     */

    /** The strip itself: layout, the one-line type, the fit, the icon, the stops. */
    public const BASE = '.pdp-gs{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;box-sizing:border-box;container-type:inline-size;isolation:isolate;font-size:13px;line-height:1.35;text-align:start;color:var(--pgs-ink,#2A2228);--pgs-isz:17px;--pgs-isvg:17px;--pgs-ig:8px;--pgs-pad:12px}'
        .'.pdp-gs .pgs-i{position:relative;z-index:1;display:inline-grid;place-items:center;flex:none;width:var(--pgs-isz);height:var(--pgs-isz);margin-inline-end:var(--pgs-ig);color:var(--pgs-ac,#C8365E)}'
        .'.pdp-gs .pgs-i svg{position:relative;display:block;width:var(--pgs-isvg);height:var(--pgs-isvg)}'
        .'.pdp-gs .pgs-t{position:relative;z-index:1;display:block;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'
        .'font-size:min(var(--pgs-fs,15px),calc((100vw - 48px - var(--pgs-pad) - var(--pgs-isz) - var(--pgs-ig) - var(--pgs-xx,0px)) * 100 / (var(--pgs-w,2160) * 1.03)))}'
        .'@supports (font-size:1cqi){.pdp-gs .pgs-t{font-size:min(var(--pgs-fs,15px),calc((100cqi - var(--pgs-isz) - var(--pgs-ig) - var(--pgs-xx,0px)) * 100 / (var(--pgs-w,2160) * 1.03)))}}'
        .'.pdp-gs .pgs-t b{font-weight:700}.pdp-gs .pgs-l{font-weight:400;opacity:.75}'
        .'.pdp-gs.pgs-noicon{--pgs-isz:0px;--pgs-ig:0px}.pgs-noicon .pgs-i{display:none}'
        .'.pdp-gs.pgs-sp-slow{--pgs-sp:1.5}.pdp-gs.pgs-sp-fast{--pgs-sp:.6}'
        .'.pgs-still,.pgs-still *,.pgs-still::before,.pgs-still::after,.pgs-still *::before,.pgs-still *::after{animation:none!important}'
        .'@media (prefers-reduced-motion:reduce){.pdp-gs,.pdp-gs *,.pdp-gs::before,.pdp-gs::after,.pdp-gs *::before,.pdp-gs *::after{animation:none!important}}';

    /** Each group's shared look; printed with its style. */
    public const GROUP_CSS = [
        // soft.py `.sl` / minimal.py #3: no box, centred, 500/600 type, the pink gift.
        'line' => '.pgs-g-line{justify-content:center;padding:11px 6px;min-height:var(--pgs-h,0px);border-radius:var(--pgs-r,0px);background:var(--pgs-bg,transparent);font-weight:500}'
            .'.pgs-g-line .pgs-t b{font-weight:600}.pgs-g-line .pgs-l{font-weight:500;opacity:.72}',
        // minimal.py `.mg`: a card, start-aligned, a 20px gift.
        'card' => '.pgs-g-card{justify-content:flex-start;padding:12px min(16px,4vw);border-radius:var(--pgs-r,14px);min-height:var(--pgs-h,0px);--pgs-isz:20px;--pgs-isvg:20px;--pgs-ig:12px;--pgs-pad:32px}'
            .'@supports (font-size:1cqi){.pgs-g-card{--pgs-ig:clamp(8px,3cqi,12px)}}'
            .'.pgs-g-card .pgs-t b{font-weight:600}.pgs-g-card .pgs-l{font-weight:500;opacity:.72}',
        // preview.py `.kfg`: 60px, the gift in a 42px see-through circle that
        // gives way on the narrowest phones so the words stay readable.
        'col' => '.pgs-g-col{justify-content:flex-start;min-height:var(--pgs-h,60px);padding:10px min(16px,4vw) 10px min(12px,3vw);border-radius:var(--pgs-r,14px);font-weight:600;--pgs-isz:42px;--pgs-isvg:24px;--pgs-ig:12px;--pgs-pad:28px}'
            .'@supports (font-size:1cqi){.pgs-g-col{--pgs-isz:clamp(28px,11cqi,42px);--pgs-ig:clamp(6px,3cqi,12px)}}'
            .'.pgs-g-col .pgs-i{color:inherit;border-radius:50%;background:var(--pgs-ib,rgba(255,255,255,.55));box-shadow:inset 0 0 0 1px rgba(255,255,255,.6)}'
            .'.pgs-g-col .pgs-t b{font-weight:700}.pgs-g-col .pgs-l{font-weight:500;opacity:.92}',
    ];

    /* Shared pieces, joined into the styles below (constant expressions). */
    private const HAIRLINES = 'background:linear-gradient(90deg,transparent,var(--pgs-c1,#E9A3B8) 50%,transparent) top/100% 1px no-repeat,linear-gradient(90deg,transparent,var(--pgs-c1,#E9A3B8) 50%,transparent) bottom/100% 1px no-repeat,var(--pgs-bg,transparent)';

    /** preview.py A/B's band of light, as a full-width layer: the band is its first 35%. */
    private const SWEEP_KF = '@keyframes pgsSweep{0%{transform:translateX(-82%) skewX(-18deg)}60%,100%{transform:translateX(142%) skewX(-18deg)}}';

    private const SWEEP = 'content:"";position:absolute;inset:-20% 0;pointer-events:none;z-index:0;transform:translateX(-82%) skewX(-18deg);';

    /** preview.py L's glint: the 250%-wide `background-position 150% → -100%` image, moved by transform. */
    private const SHEEN = 'content:"";position:absolute;inset-block:0;inset-inline:-75%;pointer-events:none;z-index:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.85) 45%,transparent 60%);transform:translateX(-60%);';

    private const SHEEN_KF = '@keyframes pgsSheen{from{transform:translateX(-60%)}to{transform:translateX(90%)}}';

    private const BOB_KF = '@keyframes pgsBob{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(-2px) rotate(-8deg)}}';

    private const TW4_KF = '@keyframes pgsTw4{0%,100%{opacity:0;transform:scale(.4) rotate(0)}50%{opacity:1;transform:scale(1.15) rotate(45deg)}}';

    /** A shine clipped to the letters: the one kind no transform can move (see the CSS note above). */
    private const TEXT_SHINE_KF = '@keyframes pgsTextShine{from{background-position:120% 0}to{background-position:-130% 0}}';

    private const KF = '.pgs-kf{background:linear-gradient(90deg,var(--pgs-c1,#FFD43B),var(--pgs-c2,#FF9F6E) 55%,var(--pgs-c3,#FF6F91));color:var(--pgs-ink,#3A1230);box-shadow:0 6px 16px rgba(255,111,145,.25)}'
        .'.pgs-kf .cf{position:absolute;top:-8px;width:6px;height:9px;border-radius:2px;z-index:0;opacity:.9;animation:pgsFall calc(2.6s*var(--pgs-sp,1)) linear infinite}'
        .'.pgs-kf .c1{inset-inline-start:2%;background:#7B5CFF}.pgs-kf .c2{inset-inline-start:6%;background:#2EC4B6;animation-delay:.5s;animation-duration:calc(2.2s*var(--pgs-sp,1))}'
        .'.pgs-kf .c3{inset-inline-start:10%;background:#fff;animation-delay:1.2s}.pgs-kf .c4{inset-inline-start:72%;background:#FF4F8B;animation-delay:.2s;animation-duration:calc(3s*var(--pgs-sp,1))}'
        .'.pgs-kf .c5{inset-inline-start:77%;background:#1E88E5;animation-delay:1.6s}.pgs-kf .c6{inset-inline-start:81%;background:#fff;animation-delay:.8s;animation-duration:calc(2.3s*var(--pgs-sp,1))}'
        .'.pgs-kf .c7{inset-inline-start:85%;background:#7B5CFF;animation-delay:2s}.pgs-kf .c8{inset-inline-start:89%;background:#2EC4B6;animation-delay:.4s;animation-duration:calc(2.8s*var(--pgs-sp,1))}'
        .'.pgs-kf .c9{inset-inline-start:93%;background:#FF4F8B;animation-delay:1.4s}.pgs-kf .c10{inset-inline-start:97%;background:#1E88E5;animation-delay:.9s;animation-duration:calc(2.4s*var(--pgs-sp,1))}'
        .'@keyframes pgsFall{0%{transform:translateY(0) rotate(0)}100%{transform:translateY(calc(var(--pgs-h,60px) + 20px)) rotate(320deg)}}';

    /**
     * key => [label, group, extra literal class, the colours it reads (var => its own value; null = none),
     *         moves (Movement and Speed apply), icon circle (Icon circle colour applies), CSS].
     */
    public const STYLES = [
        /* ── One line (soft.py, minimal.py #3) ───────────────────────────── */
        'underline' => ['Flowing underline', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => null, 'c1' => '#F2A7BC', 'c2' => '#FFC9A8', 'c3' => '#D8C4F2'], true, false,
            // soft.py #3. The underline's `background-position 0 → -300%` (a 300% image: +6 underline widths in 8 s) is a 600% layer moved one image (+3 widths) in 4 s.
            '.pgs-s-underline{padding-bottom:13px}'
            .'.pgs-s-underline .pgs-u{position:absolute;inset-inline:18%;bottom:4px;height:2px;border-radius:2px;overflow:hidden;z-index:0}'
            .'.pgs-s-underline .pgs-u::after{content:"";position:absolute;inset-block:0;inset-inline:-250%;background:linear-gradient(90deg,var(--pgs-c1,#F2A7BC),var(--pgs-c2,#FFC9A8),var(--pgs-c3,#D8C4F2),var(--pgs-c1,#F2A7BC),var(--pgs-c2,#FFC9A8)) 0 0/50% 100%;transform:translateX(-25%);animation:pgsFlow calc(4s*var(--pgs-sp,1)) linear infinite}'
            .'@keyframes pgsFlow{from{transform:translateX(-25%)}to{transform:translateX(25%)}}'],
        'sway' => ['Pink hairlines + gift sway', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => null, 'c1' => '#E9A3B8'], true, false,
            '.pgs-s-sway{'.self::HAIRLINES.'}'
            .'.pgs-s-sway .pgs-i svg{transform-origin:50% 90%;animation:pgsSway calc(6s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'@keyframes pgsSway{0%,82%,100%{transform:rotate(0)}86%{transform:rotate(-12deg)}90%{transform:rotate(10deg)}94%{transform:rotate(-5deg)}}'],
        'pill' => ['Blush pill + slow glint', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'c1' => '#FDE7EE', 'c2' => '#FFEFE4'], true, false,
            '.pgs-s-pill{padding:10px 16px;border-radius:var(--pgs-r,99px);background:linear-gradient(90deg,var(--pgs-c1,#FDE7EE),var(--pgs-c2,#FFEFE4));--pgs-pad:32px}'
            .'.pgs-s-pill::after{content:"";position:absolute;inset:0;pointer-events:none;z-index:2;background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.8) 15%,transparent 30%);transform:translateX(-36%);animation:pgsGlint calc(9s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'@keyframes pgsGlint{0%,72%{transform:translateX(-36%)}100%{transform:translateX(114%)}}'],
        'rose' => ['Rose shimmer words', 'line', '', ['ink' => '#2A2228', 'ac' => '#B4304F', 'bg' => null, 'c1' => '#F08FAB'], true, false,
            '.pgs-s-rose .pgs-i{color:var(--pgs-ac,#C8365E)}'
            .'.pgs-s-rose .pgs-t b{background:linear-gradient(100deg,var(--pgs-ac,#B4304F) 0%,var(--pgs-ac,#B4304F) 40%,var(--pgs-c1,#F08FAB) 50%,var(--pgs-ac,#B4304F) 60%,var(--pgs-ac,#B4304F) 100%) 0 0/250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:pgsTextShine calc(7s*var(--pgs-sp,1)) linear infinite}'
            .self::TEXT_SHINE_KF],
        'twinkles' => ['Hairlines + twinkles', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => null, 'c1' => '#E9A3B8'], true, false,
            '.pgs-s-twinkles{'.self::HAIRLINES.'}'
            .'.pgs-s-twinkles .tw{position:absolute;font-style:normal;font-size:8px;line-height:1;color:#E3A93B;opacity:0;animation:pgsTw calc(4s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-twinkles .tw1{top:-7px;inset-inline-start:-7px}.pgs-s-twinkles .tw2{bottom:-6px;inset-inline-end:-7px;color:var(--pgs-ac,#C8365E);animation-delay:calc(2s*var(--pgs-sp,1))}'
            .'@keyframes pgsTw{0%,100%{opacity:0;transform:scale(.5)}50%{opacity:1;transform:scale(1)}}'],
        'band' => ['Soft band + breathing gift', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => '#FBE6EC'], true, false,
            '.pgs-s-band{padding:10px 12px;border-radius:var(--pgs-r,10px);background:var(--pgs-bg,#FBE6EC);--pgs-pad:24px}'
            .'.pgs-s-band .pgs-i svg{animation:pgsBreath calc(3.2s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'@keyframes pgsBreath{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}'],
        'plain' => ['Plain line', 'line', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => null, 'c1' => '#EBDCE1'], false, false,
            // minimal.py #3: a note between two hairlines, no motion.
            '.pgs-s-plain{padding:11px 4px;border-block:1px solid var(--pgs-c1,#EBDCE1);--pgs-pad:8px}'],

        /* ── Calm cards (minimal.py #1, #2, #4, #5) ──────────────────────── */
        'quiet' => ['Quiet card', 'card', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => '#FFFFFF', 'c1' => '#EFE3E7'], false, false,
            '.pgs-s-quiet{background:var(--pgs-bg,#fff);border:1px solid var(--pgs-c1,#EFE3E7)}'],
        'blush' => ['Blush tint', 'card', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => '#FFF7F9'], false, true,
            '.pgs-s-blush{padding:11px min(14px,3.5vw);background:var(--pgs-bg,#FFF7F9);--pgs-isz:36px;--pgs-pad:28px}@supports (font-size:1cqi){.pgs-s-blush{--pgs-isz:clamp(28px,11cqi,36px)}}'
            .'.pgs-s-blush .pgs-i{border-radius:50%;background:var(--pgs-ib,#FBE6EC)}'],
        'blushglint' => ['Blush + soft glint', 'card', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => '#FFF7F9'], true, true,
            '.pgs-s-blushglint{padding:11px min(14px,3.5vw);background:var(--pgs-bg,#FFF7F9);--pgs-isz:36px;--pgs-pad:28px}@supports (font-size:1cqi){.pgs-s-blushglint{--pgs-isz:clamp(28px,11cqi,36px)}}'
            .'.pgs-s-blushglint .pgs-i{border-radius:50%;background:var(--pgs-ib,#FBE6EC)}'
            .'.pgs-s-blushglint::after{content:"";position:absolute;inset:0;pointer-events:none;z-index:2;background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.7) 20%,transparent 40%);transform:translateX(-44%);animation:pgsGlint2 calc(10s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'@keyframes pgsGlint2{0%,70%{transform:translateX(-44%)}100%{transform:translateX(104%)}}'],
        'outline' => ['Pink outline', 'card', '', ['ink' => '#2A2228', 'ac' => '#C8365E', 'bg' => '#FFFFFF', 'c1' => '#E7A9BB'], false, false,
            '.pgs-s-outline{background:var(--pgs-bg,#fff);border:1px solid var(--pgs-c1,#E7A9BB)}.pgs-s-outline .pgs-t b{color:var(--pgs-ac,#C8365E)}'],

        /* ── Colourful (preview.py) ──────────────────────────────────────── */
        'a' => ['Rose shimmer (A)', 'col', '', ['ink' => '#9E2347', 'c1' => '#FBE3EA', 'c2' => '#F7CFDA', 'c3' => '#F2B8C8'], true, true,
            '.pgs-s-a{background:linear-gradient(90deg,var(--pgs-c1,#FBE3EA),var(--pgs-c2,#F7CFDA) 50%,var(--pgs-c1,#FBE3EA));color:var(--pgs-ink,#9E2347);box-shadow:inset 0 0 0 1px var(--pgs-c3,#F2B8C8)}'
            .'.pgs-s-a::after{'.self::SWEEP.'background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.75) 17.5%,transparent 35%);animation:pgsSweep calc(3.2s*var(--pgs-sp,1)) ease-in-out infinite}'
            .self::SWEEP_KF],
        'b' => ['Berry shine (B)', 'col', '', ['ink' => '#FFFFFF', 'c1' => '#B42A52', 'c2' => '#C8365E', 'c3' => '#E0607E'], true, true,
            '.pgs-s-b{background:linear-gradient(135deg,var(--pgs-c1,#B42A52),var(--pgs-c2,#C8365E) 45%,var(--pgs-c3,#E0607E));color:var(--pgs-ink,#fff);box-shadow:0 6px 16px rgba(200,54,94,.28)}'
            .'.pgs-s-b .pgs-i{background:var(--pgs-ib,rgba(255,255,255,.2));box-shadow:inset 0 0 0 1px rgba(255,255,255,.35);animation:pgsBob calc(1.8s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-b::after{'.self::SWEEP.'background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.45) 17.5%,transparent 35%);animation:pgsSweep calc(3.2s*var(--pgs-sp,1)) ease-in-out infinite}'
            .self::SWEEP_KF.self::BOB_KF],
        'c' => ['Black & gold (C)', 'col', '', ['ac' => '#E9C46A', 'c1' => '#1F1B1E', 'c2' => '#2E2629'], true, true,
            '.pgs-s-c{background:linear-gradient(135deg,var(--pgs-c1,#1F1B1E),var(--pgs-c2,#2E2629));color:var(--pgs-ac,#E9C46A);box-shadow:inset 0 0 0 1px rgba(233,196,106,.35)}'
            .'.pgs-s-c .pgs-i{color:var(--pgs-ac,#E9C46A);background:var(--pgs-ib,rgba(255,255,255,.08));box-shadow:inset 0 0 0 1px rgba(255,255,255,.18)}'
            .'.pgs-s-c .pgs-t{background:linear-gradient(100deg,#C99A3B 0%,#F6E3A1 22%,#C99A3B 44%,#C99A3B 100%) 0 0/250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:pgsTextShine calc(3.4s*var(--pgs-sp,1)) linear infinite}'
            .self::TEXT_SHINE_KF],
        'd' => ['Running ticker (D)', 'col', '', ['ink' => '#9E2347', 'ac' => '#C8365E', 'c1' => '#F9D6E0', 'c2' => '#FBE7ED', 'c3' => '#F2B8C8'], true, true,
            // It scrolls by design, so its words keep the largest size and never shrink.
            '.pgs-s-d{padding:10px 0;background:linear-gradient(90deg,var(--pgs-c1,#F9D6E0),var(--pgs-c2,#FBE7ED));color:var(--pgs-ink,#9E2347);box-shadow:inset 0 0 0 1px var(--pgs-c3,#F2B8C8);--pgs-isz:34px;--pgs-ig:0px}'
            .'.pdp-gs.pgs-s-d{justify-content:flex-start;--pgs-isz:34px}'
            .'.pgs-s-d .pgs-run{display:flex;align-items:center;gap:8px;flex:none;white-space:nowrap;width:max-content;animation:pgsRun calc(16s*var(--pgs-sp,1)) linear infinite}'
            .'.pgs-s-d .pgs-run>*{flex:none}.pdp-gs.pgs-s-d .pgs-t{font-size:var(--pgs-fs,15px);overflow:visible}'
            .'.pgs-s-d .pgs-sep{color:var(--pgs-ac,#C8365E);margin:0 10px}'
            .'@keyframes pgsRun{to{transform:translateX(-50%)}}'
            .'[dir=rtl] .pgs-s-d .pgs-run{animation-name:pgsRunR}@keyframes pgsRunR{to{transform:translateX(50%)}}'],
        'e' => ['Glowing border (E)', 'col', '', ['ink' => '#C8365E', 'bg' => '#FFFFFF', 'c1' => '#F7CFDA', 'c2' => '#C8365E', 'c3' => '#E9B44C'], true, true,
            '.pgs-s-e{padding:11px min(15px,3.5vw);color:var(--pgs-ink,#C8365E)}'
            .'.pgs-s-e::before{content:"";position:absolute;inset:-150%;z-index:-2;background:conic-gradient(from 0deg,var(--pgs-c1,#F7CFDA),var(--pgs-c2,#C8365E),var(--pgs-c3,#E9B44C),var(--pgs-c1,#F7CFDA) 40%,var(--pgs-c1,#F7CFDA));animation:pgsSpin calc(3s*var(--pgs-sp,1)) linear infinite}'
            .'.pgs-s-e::after{content:"";position:absolute;inset:2px;z-index:-1;border-radius:calc(var(--pgs-r,14px) - 4px);background:var(--pgs-bg,#fff)}'
            .'@keyframes pgsSpin{to{transform:rotate(1turn)}}'],
        'f' => ['Candy stripes (F)', 'col', '', ['ink' => '#9E2347', 'bg' => '#FFFFFF', 'c1' => '#F4B7C8', 'c2' => '#FBE3EA'], true, true,
            // The stripes' `background-position 0 → 34px` is a layer 34px wider at each end, moved 34px.
            '.pgs-s-f{padding:14px min(24px,4vw) 14px min(16px,3vw);color:var(--pgs-ink,#9E2347);--pgs-ig:10px;--pgs-pad:40px}'
            .'.pgs-s-f::before{content:"";position:absolute;inset-block:0;inset-inline:-34px;z-index:-1;background:repeating-linear-gradient(-45deg,var(--pgs-c1,#F4B7C8) 0 12px,var(--pgs-c2,#FBE3EA) 12px 24px);background-size:34px 34px;animation:pgsStripe calc(1.6s*var(--pgs-sp,1)) linear infinite}'
            .'.pgs-s-f::after{content:"";position:absolute;inset:8px min(10px,1.5vw);z-index:0;border-radius:14px;background:var(--pgs-bg,#fff);box-shadow:0 2px 8px rgba(158,35,71,.15)}'
            .'@keyframes pgsStripe{to{transform:translateX(34px)}}'],
        'g' => ['Aurora (G)', 'col', '', ['ink' => '#5A1F35', 'c1' => '#F9C5D5', 'c2' => '#FDE1C8', 'c3' => '#E5D4F7'], true, true,
            // `background-position 0 → 100%` of a 300% image: a 3× layer moved two strip widths, there and back.
            '.pgs-s-g{color:var(--pgs-ink,#5A1F35)}'
            .'.pgs-s-g::before{content:"";position:absolute;inset-block:0;inset-inline:-100%;z-index:-1;background:linear-gradient(110deg,var(--pgs-c1,#F9C5D5),var(--pgs-c2,#FDE1C8),var(--pgs-c3,#E5D4F7),var(--pgs-c1,#F9C5D5),var(--pgs-c2,#FDE1C8));transform:translateX(33.333%);animation:pgsAur calc(7s*var(--pgs-sp,1)) ease-in-out infinite alternate}'
            .'@keyframes pgsAur{from{transform:translateX(33.333%)}to{transform:translateX(-33.333%)}}'],
        'h' => ['Twinkle (H)', 'col', '', ['ink' => '#9E2347', 'ac' => '#C8365E', 'c1' => '#FFF1F5', 'c2' => '#FBE3EA', 'c3' => '#F5C6D3'], true, true,
            '.pgs-s-h{background:linear-gradient(90deg,var(--pgs-c1,#FFF1F5),var(--pgs-c2,#FBE3EA));color:var(--pgs-ink,#9E2347);box-shadow:inset 0 0 0 1px var(--pgs-c3,#F5C6D3)}'
            .'.pdp-gs.pgs-s-h .pgs-i{color:var(--pgs-ac,#C8365E);animation:pgsBob calc(1.8s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-h .tw{position:absolute;z-index:0;font-style:normal;font-size:11px;line-height:1;color:#E9B44C;opacity:0;animation:pgsTw4 calc(2.4s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-h .tw1{inset-inline-start:5%;top:18%}.pgs-s-h .tw2{inset-inline-start:14%;bottom:12%;animation-delay:.6s;color:var(--pgs-ac,#C8365E)}'
            .'.pgs-s-h .tw3{inset-inline-end:6%;top:16%;animation-delay:1.2s}.pgs-s-h .tw4{inset-inline-end:15%;bottom:14%;animation-delay:1.8s;color:var(--pgs-ac,#C8365E)}'
            .self::BOB_KF.self::TW4_KF],
        'i' => ['Rainbow flow (I)', 'col', '', ['ink' => '#FFFFFF'], true, true,
            // `background-position 0 → 300%` of a 300% image: a 4× layer moved one image (3 strip widths) every 3 s.
            '.pgs-s-i{color:var(--pgs-ink,#fff);text-shadow:0 1px 2px rgba(0,0,0,.25);box-shadow:0 6px 16px rgba(123,92,255,.25)}'
            .'.pgs-s-i .pgs-i{background:var(--pgs-ib,rgba(255,255,255,.2));box-shadow:inset 0 0 0 1px rgba(255,255,255,.35)}'
            .'.pgs-s-i::before{content:"";position:absolute;inset-block:0;inset-inline:-150%;z-index:-1;background:linear-gradient(90deg,#FF4F8B,#FF8A3D,#FFC93C,#2EC4B6,#7B5CFF,#FF4F8B) 0 0/75% 100%;transform:translateX(37.5%);animation:pgsFlowI calc(3s*var(--pgs-sp,1)) linear infinite}'
            .'@keyframes pgsFlowI{from{transform:translateX(37.5%)}to{transform:translateX(-37.5%)}}'],
        'j' => ['Neon night (J)', 'col', '', ['ink' => '#7FF3FF', 'ac' => '#FF7AD1', 'c1' => '#3B1A6B', 'c2' => '#1A0B33'], true, true,
            '.pgs-s-j{background:radial-gradient(120% 140% at 50% 0,var(--pgs-c1,#3B1A6B),var(--pgs-c2,#1A0B33));color:#FFE3F3;box-shadow:inset 0 0 0 1.5px #FF4FB8,0 0 14px rgba(255,79,184,.35)}'
            .'.pgs-s-j .pgs-t{padding-block:6px}'
            .'.pgs-s-j .pgs-t b{color:var(--pgs-ac,#FF7AD1);text-shadow:0 0 6px #FF4FB8,0 0 14px rgba(255,79,184,.7);animation:pgsNeon calc(2.4s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pdp-gs.pgs-s-j .pgs-l{opacity:1;color:var(--pgs-ink,#7FF3FF);text-shadow:0 0 6px #31D8FF,0 0 12px rgba(49,216,255,.6)}'
            .'.pdp-gs.pgs-s-j .pgs-i{color:var(--pgs-ink,#7FF3FF);filter:drop-shadow(0 0 4px #31D8FF);background:var(--pgs-ib,rgba(255,255,255,.08));box-shadow:inset 0 0 0 1px rgba(255,255,255,.18)}'
            .'@keyframes pgsNeon{0%,100%{opacity:1}45%{opacity:.82}50%{opacity:1}55%{opacity:.9}}'],
        'k' => ['Confetti party (K)', 'col', ' pgs-kf', ['ink' => '#3A1230', 'c1' => '#FFD43B', 'c2' => '#FF9F6E', 'c3' => '#FF6F91'], true, true,
            self::KF],
        'ka' => ['Confetti + Rose shimmer (KA)', 'col', ' pgs-kf', ['ink' => '#3A1230', 'c1' => '#FFD43B', 'c2' => '#FF9F6E', 'c3' => '#FF6F91'], true, true,
            self::KF.'.pgs-s-ka::after{'.self::SWEEP.'background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.75) 17.5%,transparent 35%);animation:pgsSweep calc(6.5s*var(--pgs-sp,1)) ease-in-out infinite}'.self::SWEEP_KF],
        'kb' => ['Confetti + Berry (KB)', 'col', ' pgs-kf', ['ink' => '#3A1230', 'c1' => '#FFD43B', 'c2' => '#FF9F6E', 'c3' => '#FF6F91'], true, true,
            self::KF.'.pgs-s-kb::after{'.self::SWEEP.'background:linear-gradient(100deg,transparent 0,rgba(255,255,255,.45) 17.5%,transparent 35%);animation:pgsSweep calc(6.5s*var(--pgs-sp,1)) ease-in-out infinite}'.self::SWEEP_KF],
        'kl' => ['Confetti + Holographic (KL)', 'col', ' pgs-kf', ['ink' => '#3A1230', 'c1' => '#FFD43B', 'c2' => '#FF9F6E', 'c3' => '#FF6F91'], true, true,
            self::KF.'.pgs-s-kl::after{'.self::SHEEN.'animation:pgsSheen calc(6s*var(--pgs-sp,1)) linear infinite}'.self::SHEEN_KF],
        'l' => ['Holographic (L)', 'col', '', ['ink' => '#4A1D5C', 'c1' => '#FFC6E5', 'c2' => '#C8F7E4', 'c3' => '#D9C8FF'], true, true,
            // `background-position 0 → 100%` of a 250% image, there and back: a 2.5× layer moved 1.5 strip widths.
            '.pgs-s-l{color:var(--pgs-ink,#4A1D5C);outline:1px solid rgba(255,255,255,.8);outline-offset:-1px;box-shadow:0 4px 14px rgba(150,120,220,.25)}'
            .'.pgs-s-l::before{content:"";position:absolute;inset-block:0;inset-inline:-75%;z-index:-1;background:linear-gradient(115deg,var(--pgs-c1,#FFC6E5),var(--pgs-c2,#C8F7E4) 25%,var(--pgs-c3,#D9C8FF) 50%,#BDE6FF 75%,var(--pgs-c1,#FFC6E5));transform:translateX(30%);animation:pgsHolo calc(5s*var(--pgs-sp,1)) ease-in-out infinite alternate}'
            .'.pgs-s-l::after{'.self::SHEEN.'animation:pgsSheen calc(3s*var(--pgs-sp,1)) linear infinite}'
            .'.pgs-s-l .gl{position:absolute;z-index:0;font-style:normal;color:#fff;font-size:10px;line-height:1;text-shadow:0 0 4px #fff;opacity:0;animation:pgsTw4 calc(2s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-l .g1{inset-inline-start:7%;top:20%}.pgs-s-l .g2{inset-inline-end:8%;bottom:18%;animation-delay:.7s}.pgs-s-l .g3{inset-inline-end:22%;top:14%;animation-delay:1.3s}'
            .'@keyframes pgsHolo{from{transform:translateX(30%)}to{transform:translateX(-30%)}}'.self::SHEEN_KF.self::TW4_KF],
        'm' => ['Gift tag + arrows (M)', 'col', '', ['ink' => '#FFFFFF', 'ac' => '#14B8A6', 'c1' => '#FF3D7F', 'c2' => '#FF6F61'], true, false,
            '.pdp-gs.pgs-s-m{padding:0;padding-inline-end:14px;align-items:stretch;background:linear-gradient(90deg,var(--pgs-c1,#FF3D7F),var(--pgs-c2,#FF6F61));color:var(--pgs-ink,#fff);box-shadow:0 6px 16px rgba(255,61,127,.28);--pgs-isz:64px;--pgs-ig:6px;--pgs-pad:14px}'
            .'@supports (font-size:1cqi){.pdp-gs.pgs-s-m{--pgs-isz:clamp(40px,15cqi,64px)}.pgs-s-m .pgs-i::before{width:clamp(26px,10cqi,42px);height:clamp(26px,10cqi,42px)}}'
            .'.pdp-gs.pgs-s-m .pgs-i{height:auto;border-radius:0;box-shadow:none;background:var(--pgs-ac,#14B8A6);clip-path:polygon(0 0,82% 0,100% 50%,82% 100%,0 100%)}'
            .'[dir=rtl] .pdp-gs.pgs-s-m .pgs-i{clip-path:polygon(18% 0,100% 0,100% 100%,18% 100%,0 50%)}'
            .'.pgs-s-m .pgs-i::before{content:"";position:absolute;inset:0;margin:auto;width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.2);box-shadow:inset 0 0 0 1px rgba(255,255,255,.35)}'
            .'.pgs-s-m .pgs-i svg{animation:pgsWig calc(1.6s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-m .pgs-arr{position:relative;z-index:1;display:inline-flex;align-items:center;flex:none;margin-inline-end:10px;color:#FFE066;animation:pgsArr calc(1.2s*var(--pgs-sp,1)) ease-in-out infinite}'
            .'.pgs-s-m .pgs-t{align-self:center;padding-block:10px;--pgs-xx:40px}'
            .'[dir=rtl] .pgs-s-m .pgs-arr svg{transform:scaleX(-1)}[dir=rtl] .pgs-s-m .pgs-arr{animation-name:pgsArrR}'
            .'@container (max-width:340px){.pgs-s-m .pgs-arr{display:none}.pgs-s-m .pgs-t{--pgs-xx:0px}}'
            .'@keyframes pgsWig{0%,60%,100%{transform:rotate(0)}70%{transform:rotate(-14deg)}80%{transform:rotate(12deg)}90%{transform:rotate(-6deg)}}'
            .'@keyframes pgsArr{0%,100%{transform:translateX(-3px);opacity:.6}50%{transform:translateX(3px);opacity:1}}'
            .'@keyframes pgsArrR{0%,100%{transform:translateX(3px);opacity:.6}50%{transform:translateX(-3px);opacity:1}}'],
    ];

    /** The colour fields and the custom property each one drives. */
    public const COLOUR_VARS = [
        'ink' => 'ink', 'accent' => 'ac', 'bg' => 'bg', 'c1' => 'c1', 'c2' => 'c2', 'c3' => 'c3', 'icon_bg' => 'ib',
    ];

    /** The gift, with its own size (CLAUDE.md: every inline <svg> has width/height). */
    private const GIFT = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';

    private const ARROWS = '<span class="pgs-arr" aria-hidden="true"><svg viewBox="0 0 30 16" width="30" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2l6 6-6 6M12 2l6 6-6 6M21 2l6 6-6 6"/></svg></span>';

    /**
     * Outfit's advance widths at weight 700, in hundredths of an em, for the
     * printable ASCII characters (space … tilde) — measured in Chromium on the
     * product page (Lane GS2). Any other character (Arabic, accents) counts
     * NON_ASCII, a little wider than Cairo's Arabic at 700 (≈ 0.48 em).
     */
    private const WIDTHS = [19, 29, 46, 66, 60, 69, 69, 25, 31, 31, 49, 56, 28, 46, 30, 38, 67, 38, 56, 56, 60, 56, 57, 52, 56, 57, 29, 28, 56, 56, 56, 50, 76, 69, 64, 66, 75, 62, 59, 79, 73, 29, 49, 69, 56, 86, 74, 82, 62, 82, 64, 57, 62, 70, 68, 98, 67, 65, 58, 35, 38, 35, 47, 51, 32, 60, 60, 49, 60, 55, 36, 58, 57, 26, 33, 55, 26, 87, 57, 58, 60, 60, 43, 46, 35, 54, 52, 77, 51, 52, 48, 34, 30, 34, 56];

    private const NON_ASCII = 56;

    public const SCHEMA = [
        'on' => ['bool', 'Show the free gifts strip', true,
            'The whole strip, on every product page. Off removes it from the page entirely. Where it sits on a phone, and whether it shows there, is its row on the Mobile sections tab.'],
        'desk' => ['select', 'Position · laptop', 'paylater',
            'Which part of the buy column it sits above. It shows in this place even when Tabby & Tamara is switched off.',
            self::DESK],
        'style' => ['select', 'Style', 'underline',
            'One line, a calm card or one of the colourful designs. Every style keeps the words on one line and sizes them to the screen.',
            [
                'underline' => 'Flowing underline', 'sway' => 'Pink hairlines + gift sway', 'pill' => 'Blush pill + slow glint',
                'rose' => 'Rose shimmer words', 'twinkles' => 'Hairlines + twinkles', 'band' => 'Soft band + breathing gift', 'plain' => 'Plain line',
                'quiet' => 'Quiet card', 'blush' => 'Blush tint', 'blushglint' => 'Blush + soft glint', 'outline' => 'Pink outline',
                'a' => 'Rose shimmer (A)', 'b' => 'Berry shine (B)', 'c' => 'Black & gold (C)', 'd' => 'Running ticker (D)',
                'e' => 'Glowing border (E)', 'f' => 'Candy stripes (F)', 'g' => 'Aurora (G)', 'h' => 'Twinkle (H)',
                'i' => 'Rainbow flow (I)', 'j' => 'Neon night (J)', 'k' => 'Confetti party (K)', 'ka' => 'Confetti + Rose shimmer (KA)',
                'kb' => 'Confetti + Berry (KB)', 'kl' => 'Confetti + Holographic (KL)', 'l' => 'Holographic (L)', 'm' => 'Gift tag + arrows (M)',
            ]],
        'line1' => ['text', 'Words · bold part', 'Free gifts with every order.', 'Plain text. Shown first, in bold, on the same line as the rest.'],
        'line2' => ['text', 'Words · lighter part', 'Limited time only!', 'Plain text, after the bold part on the same line. Leave empty for the bold part alone.'],
        'line1_ar' => ['text', 'Bold part — Arabic', '', 'Leave empty for the standard Arabic: هدايا مجانية مع كل طلب.'],
        'line2_ar' => ['text', 'Lighter part — Arabic', '', 'Leave empty for the standard Arabic: لفترة محدودة فقط!'],
        'ink' => ['type' => 'colour', 'label' => 'Text colour', 'default' => '', 'help' => 'Empty: the style’s own colour.'],
        'accent' => ['type' => 'colour', 'label' => 'Accent colour', 'default' => '', 'help' => 'The gift, and the words or marks the style colours. Empty: the style’s own.'],
        'bg' => ['type' => 'colour', 'label' => 'Background', 'default' => '', 'help' => 'Empty: the style’s own (none for the plain one-line styles).'],
        'c1' => ['type' => 'colour', 'label' => 'Colour 1 · lines / gradient start', 'default' => '', 'help' => 'Empty: the style’s own.'],
        'c2' => ['type' => 'colour', 'label' => 'Colour 2 · gradient middle', 'default' => '', 'help' => 'Empty: the style’s own.'],
        'c3' => ['type' => 'colour', 'label' => 'Colour 3 · gradient end', 'default' => '', 'help' => 'Empty: the style’s own.'],
        'icon_bg' => ['type' => 'colour', 'label' => 'Icon circle colour', 'default' => '', 'help' => 'Drawn see-through (55%). Empty: the style’s own.'],
        'icon' => ['bool', 'Show the gift icon', true, 'The small gift beside the words.'],
        'confetti' => ['bool', 'Falling confetti', true, 'Ten small pieces falling at the two ends of the strip (the Confetti styles).'],
        'shine' => ['bool', 'Movement', true, 'The style’s one movement — the flowing underline, a glint, a sway. Off keeps the style still.'],
        'speed' => ['select', 'Movement speed', 'normal', 'How fast the style’s movement runs.', [
            'slow' => 'Slow',
            'normal' => 'Normal (as approved)',
            'fast' => 'Fast',
        ]],
        'fs' => ['type' => 'range', 'label' => 'Largest font size', 'default' => 15, 'help' => 'The words never go above this. On a narrower screen they shrink to keep one line, never below what fits.',
            'options' => ['min' => 11, 'max' => 24, 'step' => 0.5, 'unit' => 'px'], 'rule' => [self::class, 'halfPx']],
        'height' => ['type' => 'range', 'label' => 'Height', 'default' => 0, 'help' => '0 = the style’s own height. Otherwise the strip’s smallest height.',
            'options' => ['min' => 0, 'max' => 120, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'radius' => ['type' => 'range', 'label' => 'Corner roundness', 'default' => -1, 'help' => '−1 = the style’s own corners.',
            'options' => ['min' => -1, 'max' => 40, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'above' => ['type' => 'range', 'label' => 'Space above', 'default' => 24,
            'help' => 'Phones and laptops. On a phone, a number typed in the strip’s own “Space above” on the Mobile sections tab wins over this one.',
            'options' => ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
        'below' => ['type' => 'range', 'label' => 'Space below', 'default' => 24, 'help' => 'Phones and laptops.',
            'options' => ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'px'], 'rule' => [self::class, 'intPx']],
    ];

    public const TABS = [
        'giftstrip' => ['Product gifts strip',
            'The “Free gifts with every order.” strip on the product page: on or off, where it sits, its style, its words in English and Arabic, its colours, effects and sizes.',
            ['on', 'desk', 'style', 'line1', 'line2', 'line1_ar', 'line2_ar', 'ink', 'accent', 'bg', 'c1', 'c2', 'c3', 'icon_bg', 'icon', 'confetti', 'shine', 'speed', 'fs', 'height', 'radius', 'above', 'below']],
    ];

    public const POLICY = [
        'max' => 80,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'strip',
    ];

    /** The English defaults' InterfaceStrings keys, so /ar reads Arabic while they are unchanged. */
    private const KEYED = [
        'line1' => 'store.product.gift_strip_1',
        'line2' => 'store.product.gift_strip_2',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (array $f) => $f['default'], self::fields());
    }

    /**
     * Every value, saved or shipped — and every one re-cast on the way OUT,
     * so a row written past save() (an import, a hand edit on the server)
     * still cannot put anything but its own type on the page.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $out = [];

        foreach (self::fields() as $key => $field) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $this->memo = $out;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        $fields = self::fields();

        foreach ($values as $key => $value) {
            if (isset($fields[$key])) {
                $this->settings->set(self::PREFIX.$key, ModuleSchema::cast($fields[$key], $value));
            }
        }

        $this->memo = null;
    }

    /** @return list<array<string, mixed>> the screen's ModuleSchema::tabs() payload */
    public function tabs(): array
    {
        return ModuleSchema::tabs(self::SCHEMA, self::TABS, $this->all(), self::POLICY);
    }

    /**
     * Everything the admin's Style select and live sample need, from this
     * file's constants: each style's group, own colours and what applies to
     * it, its markup with `%T%` where the words go, and every style's CSS.
     *
     * @return array<string, mixed>
     */
    public static function adminPayload(): array
    {
        $styles = [];

        foreach (self::STYLES as $key => [$label, $group, , $own, $moves, $circle]) {
            $styles[$key] = [
                'label' => $label,
                'group' => $group,
                'own' => array_map(static fn ($v) => $v ?? '', $own),
                'moves' => $moves,
                'circle' => $circle,
                'confetti' => str_starts_with($key, 'k'),
                'classes' => self::styleClasses($key),
                'inner' => self::inner($key, '%T%', true, true),
            ];
        }

        return ['groups' => self::GROUPS, 'styles' => $styles, 'css' => self::adminCss(), 'widths' => self::WIDTHS, 'nonAscii' => self::NON_ASCII];
    }

    /** Every style's CSS once, for the admin sample only. Never on the shop. */
    public static function adminCss(): string
    {
        return self::BASE.implode('', self::GROUP_CSS).implode('', array_column(self::STYLES, 6));
    }

    /**
     * A whole number of pixels inside the field's own bounds; anything that is
     * not a number is the default (ModuleSchema's clamp would read "lots" as 0).
     *
     * @param  array<string, mixed>  $field
     */
    public static function intPx(mixed $raw, array $field): int
    {
        if (! is_numeric($raw) || is_bool($raw) || ! is_finite((float) $raw)) {
            return (int) $field['default'];
        }

        $o = is_array($field['options'] ?? null) ? $field['options'] : [];

        return (int) max((int) ($o['min'] ?? 0), min((int) ($o['max'] ?? 0), (int) round((float) $raw)));
    }

    /**
     * A font size: a number to the nearest half pixel, inside the field's own
     * bounds; anything else is the default.
     *
     * @param  array<string, mixed>  $field
     */
    public static function halfPx(mixed $raw, array $field): float
    {
        if (! is_numeric($raw) || is_bool($raw)) {
            return (float) $field['default'];
        }

        $v = (float) $raw;

        if (! is_finite($v)) {
            return (float) $field['default'];
        }

        $o = is_array($field['options'] ?? null) ? $field['options'] : [];

        return max((float) ($o['min'] ?? 10), min((float) ($o['max'] ?? 24), round($v * 2) / 2));
    }

    /**
     * A colour on its way to the page: `#RRGGBB` (three digits expanded), or
     * '' — "the style's own". ModuleSchema's colour cast already stores only
     * a hex or the default '' (an empty box, or anything broken); this is the
     * second lock on the way out.
     */
    public static function colourOrOwn(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        $hex = self::hex($raw, '');

        return $hex === '' ? '' : $hex;
    }

    /* ═══════════════════════ what reaches the storefront ═══════════════════ */

    public function on(): bool
    {
        return (bool) ($this->all()['on'] ?? false);
    }

    /** One of a select's own keys, or its default. Rule 5's second lock. */
    public function choice(string $key): string
    {
        $f = self::fields()[$key];
        $v = (string) ($this->all()[$key] ?? '');

        return is_array($f['options']) && array_key_exists($v, $f['options']) && ($key !== 'style' || isset(self::STYLES[$v]))
            ? $v : (string) $f['default'];
    }

    /** The chosen style: a key of STYLES. */
    public function styleKey(): string
    {
        return $this->choice('style');
    }

    /** Where the laptop draws it: a DESK key. */
    public function desk(): string
    {
        return $this->choice('desk');
    }

    /** The DOM anchor the template draws the element at — 'off' sits in the default place, hidden on laptops. */
    public function anchor(): string
    {
        $d = $this->desk();

        return $d === 'off' ? 'paylater' : $d;
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    /**
     * A part of the words in the page's language. English: his words. Arabic:
     * his Arabic, or — while it is empty — the InterfaceStrings key, which
     * prints the published Arabic; never his English on the Arabic page unless
     * the key has no Arabic yet.
     */
    public function line(int $n): string
    {
        $key = 'line'.($n === 2 ? 2 : 1);
        $all = $this->all();

        if (! Locale::isDefault() && Locale::current() === 'ar') {
            $ar = trim((string) ($all[$key.'_ar'] ?? ''));

            if ($ar !== '') {
                return $ar;
            }

            return (string) __(self::KEYED[$key]);
        }

        return trim((string) ($all[$key] ?? ''));
    }

    /**
     * The CSS this page needs for the strip: the base, the group and the ONE
     * chosen style. A constant of this file, never a setting, so it may be
     * printed unescaped.
     */
    public function css(): string
    {
        return self::cssFor($this->styleKey());
    }

    public static function cssFor(string $key): string
    {
        $s = self::STYLES[$key] ?? self::STYLES['underline'];

        return self::BASE.self::GROUP_CSS[$s[1]].$s[6];
    }

    /** The literal classes a style puts on the strip. */
    public static function styleClasses(string $key): string
    {
        $s = self::STYLES[$key] ?? self::STYLES['underline'];
        $key = isset(self::STYLES[$key]) ? $key : 'underline';

        return 'pgs-g-'.$s[1].' pgs-s-'.$key.$s[2];
    }

    /** Literal class names only. */
    public function classes(): string
    {
        $style = $this->styleKey();
        $out = [self::styleClasses($style)];

        if (! $this->flag('icon')) {
            $out[] = 'pgs-noicon';
        }

        if (! $this->flag('shine')) {
            $out[] = 'pgs-still';
        } elseif (($s = $this->choice('speed')) !== 'normal') {
            $out[] = 'pgs-sp-'.$s;
        }

        if ($this->desk() !== 'paylater') {
            $out[] = 'pgs-d-'.$this->desk();
        }

        return ' '.implode(' ', $out);
    }

    /**
     * The strip's inside for a style: its decorations, the gift, the words.
     * `$words` is HTML the caller has already escaped (the partial passes
     * e()'d text; the admin passes the `%T%` placeholder). Everything else is
     * a constant of this file.
     */
    public static function inner(string $key, string $words, bool $icon, bool $confetti): string
    {
        $key = isset(self::STYLES[$key]) ? $key : 'underline';
        $gift = $icon ? '<span class="pgs-i">'.self::GIFT.'</span>' : '';
        $text = '<span class="pgs-t">'.$words.'</span>';

        if ($key === 'd') {
            // Four copies so the loop is seamless; a reader hears the first only.
            $sep = '<span class="pgs-sep" aria-hidden="true">✦</span>';
            $copy = $gift.'<span class="pgs-t" aria-hidden="true">'.$words.'</span>'.$sep;

            return '<div class="pgs-run">'.$gift.$text.$sep.$copy.$copy.$copy.'</div>';
        }

        $deco = match ($key) {
            'underline' => '<i class="pgs-u" aria-hidden="true"></i>',
            'h' => '<i class="tw tw1" aria-hidden="true">✦</i><i class="tw tw2" aria-hidden="true">✦</i><i class="tw tw3" aria-hidden="true">✦</i><i class="tw tw4" aria-hidden="true">✦</i>',
            'l' => '<i class="gl g1" aria-hidden="true">✦</i><i class="gl g2" aria-hidden="true">✦</i><i class="gl g3" aria-hidden="true">✦</i>',
            default => '',
        };

        if ($confetti && str_starts_with($key, 'k')) {
            $deco .= '<i class="cf c1"></i><i class="cf c2"></i><i class="cf c3"></i><i class="cf c4"></i><i class="cf c5"></i><i class="cf c6"></i><i class="cf c7"></i><i class="cf c8"></i><i class="cf c9"></i><i class="cf c10"></i>';
        }

        if ($key === 'twinkles' && $icon) {
            $gift = '<span class="pgs-i"><i class="tw tw1" aria-hidden="true">✦</i><i class="tw tw2" aria-hidden="true">✦</i>'.self::GIFT.'</span>';
        }

        return $deco.$gift.($key === 'm' ? self::ARROWS : '').$text;
    }

    /** This page's strip inside, with the words escaped here. */
    public function html(string $one, string $two): string
    {
        $words = ($one !== '' ? '<b>'.e($one).'</b>' : '')
            .($one !== '' && $two !== '' ? ' ' : '')
            .($two !== '' ? '<span class="pgs-l">'.e($two).'</span>' : '');

        return self::inner($this->styleKey(), $words, $this->flag('icon'), $this->flag('confetti'));
    }

    /**
     * The words' width in hundredths of an em, at the bold weight — the
     * number the CSS divides the strip's width by. Always at least 1 em.
     */
    public static function emWidth(string $one, string $two): int
    {
        $w = 0;

        foreach (mb_str_split($one.($one !== '' && $two !== '' ? ' ' : '').$two) as $c) {
            $o = strlen($c) === 1 ? ord($c) : 0;
            $w += $o >= 32 && $o < 127 ? self::WIDTHS[$o - 32] : self::NON_ASCII;
        }

        return max(100, $w);
    }

    /**
     * The strip's own custom properties: the words' width, then only the
     * values he has set. Integers, half-pixel numbers and `#RRGGBB` only.
     */
    public function style(string $one, string $two): string
    {
        $all = $this->all();
        $def = self::defaults();
        $out = ['--pgs-w:'.self::emWidth($one, $two)];

        foreach (self::COLOUR_VARS as $k => $var) {
            $hex = self::colourOrOwn($all[$k] ?? '');

            if ($hex === '') {
                continue;
            }

            if ($k === 'icon_bg') {
                [$r, $g, $b] = sscanf(substr($hex, 1), '%2x%2x%2x');
                $out[] = '--pgs-ib:rgba('.(int) $r.','.(int) $g.','.(int) $b.',.55)';
            } else {
                $out[] = '--pgs-'.$var.':'.$hex;
            }
        }

        $fields = self::fields();
        $px = ['height' => 'h', 'radius' => 'r', 'above' => 'mt', 'below' => 'mb'];

        foreach ($px as $k => $var) {
            $v = self::intPx($all[$k], $fields[$k]);

            if ($v !== (int) $def[$k] && ! ($k === 'height' && $v === 0) && ! ($k === 'radius' && $v < 0)) {
                $out[] = '--pgs-'.$var.':'.$v.'px';
            }
        }

        $fs = self::halfPx($all['fs'], $fields['fs']);

        if ($fs !== (float) $def['fs']) {
            $out[] = '--pgs-fs:'.rtrim(rtrim(number_format($fs, 1, '.', ''), '0'), '.').'px';
        }

        return implode(';', $out);
    }

    /** `#RRGGBB`, upper case, or the default. Three digits are expanded. */
    public static function hex(string $raw, string $default): string
    {
        $v = strtoupper(ltrim(trim($raw), '#'));

        if (preg_match('/^[0-9A-F]{3}$/', $v) === 1) {
            $v = $v[0].$v[0].$v[1].$v[1].$v[2].$v[2];
        }

        return preg_match('/^[0-9A-F]{6}$/', $v) === 1 ? '#'.$v : strtoupper($default);
    }
}
