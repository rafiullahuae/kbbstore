<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Services\FreeGifts;
use Illuminate\Http\Request;

/**
 * The live Super Sale banner on /super-sale/.                     (Lane SSB)
 *
 * The owner approved variant D of the banner study (claude.ai artifact
 * 5tGDiMhkudMkmBJvQuhrg7) and refined it: ONE compact banner, a hero (the
 * tabby and tamara marks, "SUPER SALE" on one line, coupon GLOW, the lady on
 * the side) over a free-gifts row, with the same hairline under the gift row
 * as above it. Desktop and phone, English and Arabic. It replaces the PICTURE
 * in the Super Sale header area (Pages -> Page header); the page keeps its
 * trust strip (App\Support\TrustStrip) and everything else where it was.
 *
 * ── LIVE, AND AS LIGHT AS THE PICTURE IT REPLACES ───────────────────────────
 *
 * HTML and one stylesheet built from the constants below, printed on
 * /super-sale/ only. No script, no font, no request of its own: the tabby and
 * tamara marks are the checkout's own inline SVGs (PaymentMarkArt), the gift
 * icon is a constant, and the settings are one autoloaded row read off
 * SettingsService::all() -- the request's one settings map. Sizes come from
 * one unit, --u, the banner's width in hundredths, capped (3.9px on a phone,
 * 10px on a computer); nothing is measured. The --u ladder is a plain px value,
 * then min() with vw, then min() with cqw -- each inside @supports, because a
 * declaration with var() has NO fallback in a browser that cannot read its
 * value (the 2.60.492 lotus landmine in CLAUDE.md).
 *
 * ── EVERY CONTROL, AND NOTHING MOVES UNTIL HE MOVES IT ──────────────────────
 *
 * The owner: "allow us the full controls of changing the text size, color
 * etc" and "desktop, mobile, eng arabic etc all controls i need". Each text
 * element (ELEMENTS) has a size, a colour, a weight and an offset, and the
 * copy block an alignment and a line gap -- each per device AND per language.
 * Every one is a CSS custom property whose var() FALLBACK is the shipped
 * value, and styleAttr() prints a property only where the stored value
 * differs from the shipped one: an untouched banner prints no style attribute
 * at all. Arabic inherits English unless he overrides it (resolve()); the two
 * places the shipped Arabic already differed (the title on a computer, the
 * phone's line gap) are Arabic's own defaults (AR_DEFAULTS).
 *
 * ── THE PICTURES ────────────────────────────────────────────────────────────
 *
 * A phone picture (the arch) and a computer picture (a rectangle), each with
 * an Arabic override; empty = the shipped lady. One <picture>: the computer's
 * <source> and the phone's <img>, so each device downloads only its own. A
 * picture with a transparent background (detected once, on SAVE --
 * hasAlpha(), never in the browser) defaults to the arch / rectangle drawn
 * BEHIND it rather than clipped into it, so a cutout's hair and shoulders are
 * never cut off.
 *
 * ── THE ON-PAGE EDITOR ──────────────────────────────────────────────────────
 *
 * "Edit banner" on /super-sale/ is the storefront admin layer's (admin-hint.js
 * -> storefront-admin.js -> super-sale-banner-editor.js), drawn from the
 * authenticated /admin-api/storefront/context answer (editorContext()). None
 * of it is ever in the page's HTML, so no cache can hand it to a shopper. The
 * force classes .ssb-fm / .ssb-fd let it preview the phone layout on a
 * computer and the other way round.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 *
 * Raw output is css() (built from constants only), GIFT_ICON and
 * PaymentMarkArt's constants. Every word is plain text, clipped, printed
 * escaped; every style value is a validated #RRGGBB, a weight from WEIGHTS, an
 * alignment from ALIGNS or a number clamped to its range, and the style
 * attribute is printed escaped too. The band is one of BANDS or `range:<id>`;
 * a picture is an http(s) or root-relative address checked on save AND at
 * render.
 */
final class SuperSaleBanner
{
    public const KEY = 'super_sale_banner';

    /**
     * The shipped picture, copied into uploads/appearance/ by migration
     * 2027_12_26_100000: the owner's own transparent cutout of the lady
     * (381x500 WebP with alpha, 26 October). The phone shows it, and so does
     * the computer in "Single image" mode, its default.
     */
    public const MODEL_FILE = 'kbb-super-sale-model-mobile.webp';

    public const MODEL_REL = 'uploads/appearance/'.self::MODEL_FILE;

    /** The shipped picture: [path, width, height, transparent]. */
    public const SHIPPED = [self::MODEL_REL, 381, 500, true];

    /** The size the owner is told to make a "Rectangle design" picture (about 2:1). */
    public const RECT_SIZE = [1440, 700];

    public const MAX_TEXT = 60;

    /** Gift photos drawn: six on a computer, the first five on a phone. */
    public const MAX_GIFTS = 6;

    /** --u's ceiling per device, in px: a size stored in px is px at that width. */
    public const UCAP = ['m' => 3.9, 'd' => 10.0];

    /** Every word on the banner: key => [English, Arabic, label]. */
    public const TEXTS = [
        'pay' => ['SHOP NOW, PAY LATER', 'تسوّقي الآن وادفعي لاحقاً', 'Line beside the tabby and tamara logos'],
        'tagline' => ['PERFECT SKINCARE TO GLOW', 'عناية مثالية لبشرة متوهّجة', 'Line under the logos'],
        'title' => ['SUPER SALE', 'تخفيضات كبرى', 'Big title'],
        'coupon' => ['COUPON CODE:', 'كود الخصم:', 'Words before the code'],
        'gifts' => ['Free gifts with every order', 'هدايا مجانية مع كل طلب', 'Gift row heading'],
        'free' => ['FREE', 'مجاناً', 'Chip at the end of the gift row'],
    ];

    /**
     * The text elements he can style: key => [label, selector, phone size (u),
     * computer size (u), weight, colour, display when shown, shown on a phone,
     * shown on a computer]. The sizes, weights and colours are today's, so a
     * banner nobody has touched draws exactly as approved -- and "SHOP NOW,
     * PAY LATER" is off on a computer, as the owner decided (26 October,
     * version 17 of the study); he can switch it back on per device.
     */
    public const ELEMENTS = [
        'pay' => ['Pay-later line', '.ssb-paytxt', 2.8, 1.85, 700, '#3B2F36', 'inline', true, false],
        'tag' => ['Tagline', '.ssb-tag', 3.2, 2.2, 600, '#4A3B44', 'inline', true, true],
        'title' => ['SUPER SALE heading', '.ssb-super', 8.0, 5.6, 800, '#EC3A80', 'block', true, true],
        // Keyed clabel / cvalue, never coupon / code: PublicPagesQuoteRealPricesTest
        // reads "code" or "coupon" followed by a word as an offered discount
        // code, and a property --ssb-code-c with a colour after it is exactly that.
        'clabel' => ['Coupon label', '.ssb-coupon>span', 3.5, 2.2, 700, '#2A2228', 'inline', true, true],
        'cvalue' => ['Coupon code', '.ssb-coupon b', 3.5, 2.2, 800, '#C3306A', 'inline', true, true],
        'gifts' => ['Gift row text', '.ssb-gi', 3.0, 2.0, 800, '#2E8F62', 'inline-flex', true, true],
        'free' => ['FREE chip', '.ssb-free', 2.7, 1.7, 800, '#2E8F62', 'inline-flex', true, true],
    ];

    /** Every property of an element, in the order styleAttr() prints them. */
    public const PROPS = ['s', 'w', 'c', 'x', 'y', 'v'];

    /** Per element, per device: [min, max] in px (size, at full width) or px (offsets). */
    public const RANGES = ['s' => [6, 120], 'x' => [-80, 80], 'y' => [-80, 80], 'g' => [0, 40]];

    public const WEIGHTS = [300, 400, 500, 600, 700, 800, 900];

    /** The copy block's alignment: value => [align-items, text-align, label]. */
    public const ALIGNS = ['start' => ['flex-start', 'start', 'Start'], 'center' => ['center', 'center', 'Centre'], 'end' => ['flex-end', 'end', 'End']];

    /** The copy block's line gap per device, in u (the computer's from version 17). */
    public const GAP = ['m' => 1.3, 'd' => 0.5];

    /**
     * Where the shipped Arabic already differed from the English, in px:
     * these are Arabic's own defaults, not overrides he has to know about.
     */
    public const AR_DEFAULTS = ['d' => ['title' => ['s' => 52.0], '_' => ['g' => 3.5]], 'm' => ['_' => ['g' => 3.51]]];

    /**
     * The picture slots: key => [kind, language, label]. 'single' is the one
     * picture the phone shows (and the computer in "Single image" mode);
     * 'rect' is the computer's "Rectangle design". Empty = the slot it
     * inherits from: Arabic from English, English from the shipped cutout.
     */
    public const SLOTS = [
        'model' => ['single', 'en', 'Picture (phone, and computer in “Single image”)'],
        'model_d' => ['rect', 'en', 'Rectangle design (computer)'],
        'model_ar' => ['single', 'ar', 'Picture, Arabic'],
        'model_d_ar' => ['rect', 'ar', 'Rectangle design, Arabic'],
    ];

    /** The computer's picture: one picture like the phone's, or one wide picture he designs. */
    public const MODES = ['single' => 'Single image', 'rect' => 'Rectangle design'];

    /**
     * How a picture sits. 'auto' = cut into the arch / the rectangle for a
     * photo, and for a transparent cutout NOTHING behind it -- she stands on
     * the banner itself, bottom-aligned, uncropped (the owner, 26 October:
     * "remove the background dome background"). The backdrop is an option,
     * off unless he picks it.
     */
    public const SHAPES = ['auto' => 'Automatic', 'none' => 'No backdrop', 'backdrop' => 'Arch behind the picture', 'clip' => 'Cut into the arch'];

    public const FITS = ['cover' => 'Fill the rectangle', 'contain' => 'Whole picture'];

    /** The focal point the picture is cropped around, per device (percent). */
    public const FOCUS = ['m' => [50, 12], 'd' => [50, 50]];

    /** Which gifts the row shows. `range:<id>` picks one range by its id. */
    public const BANDS = [
        'all' => 'Every gift in every range running today',
        'first' => 'The first range (the smallest basket)',
        'last' => 'The top range (the biggest basket)',
    ];

    /** The red-pink gift icon of the approved design. A constant; width/height so it never draws at 300x150. */
    public const GIFT_ICON = '<svg class="ssb-gic" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';

    /**
     * Rules for every device, the phone's numbers. `%` is the banner's root.
     * Every selector is under .kbb-ssb, and the picture rules are written
     * stronger than PageHeaders::CSS's `.kbb-ph>.kbb-ph-i img`, which would
     * otherwise give the lady and the gift photos the header picture's height.
     */
    private const BASE = [
        '& .ssb-in{position:relative;display:flex;flex-direction:column;overflow:hidden;color:#2a2228;text-align:start;'
            .'background:radial-gradient(120% 120% at 88% 8%,#fff6fa,transparent 55%),radial-gradient(130% 130% at 3% 122%,#fbe9f1,transparent 52%),linear-gradient(118deg,#fde8ef 0%,#fff8fb 48%,#fdeee6 100%)}',
        '&[dir=rtl] .ssb-in{font-family:"Cairo",var(--sans,system-ui),sans-serif}',
        '& .ssb-hero{display:flex;align-items:center;gap:calc(var(--u)*2.2);height:calc(var(--u)*44);padding:calc(var(--u)*1.6) calc(var(--u)*4.5) 0;box-sizing:border-box;min-height:0}',
        '& .ssb-copy{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:calc(var(--u)*var(--ssb-gm,1.3));padding-bottom:calc(var(--u)*1.6);align-items:var(--ssb-al-am,stretch);text-align:var(--ssb-al-tm,start)}',
        '& .ssb-pay{display:flex;align-items:center;flex-wrap:wrap;gap:calc(var(--u)*2);justify-content:var(--ssb-al-am,flex-start)}',
        '& .ssb-pay svg{display:block;height:calc(var(--u)*4.6);width:auto;max-width:100%}',
        '& .ssb-paytxt{letter-spacing:.03em;line-height:1.2}',
        '& .ssb-tag{letter-spacing:.02em;line-height:1.3}',
        '& .ssb-super{margin:0;line-height:.95;letter-spacing:-.01em;white-space:nowrap;'
            .'text-shadow:calc(var(--u)*.2) calc(var(--u)*.2) 0 #fff,calc(var(--u)*-.2) calc(var(--u)*.2) 0 #fff,calc(var(--u)*.2) calc(var(--u)*-.2) 0 #fff,calc(var(--u)*-.2) calc(var(--u)*-.2) 0 #fff,0 var(--u) calc(var(--u)*1.6) rgba(236,58,128,.25)}',
        '&[dir=rtl] .ssb-super{font-family:"Changa","Cairo",var(--sans,system-ui),sans-serif;letter-spacing:0;line-height:1.05}',
        '& .ssb-coupon{display:inline-flex;align-items:center;flex-wrap:wrap;gap:calc(var(--u)*1.4);margin-top:calc(var(--u)*.5);line-height:1.3}',
        '& .ssb-coupon b{background:#fff;border:calc(var(--u)*.28) dashed #e4457c;border-radius:var(--u);letter-spacing:.06em;padding:calc(var(--u)*.5) calc(var(--u)*2)}',
        '& .ssb-model{position:relative;flex:none;align-self:flex-end;width:calc(var(--u)*33);height:94%}',
        '& .ssb-model picture{display:block;width:100%;height:100%}',
        '& .ssb-model img,.kbb-ph>.kbb-ph-i & .ssb-model img{display:block;width:100%;height:100%;max-width:none;border-radius:0}',

        // The hairline under the gift row (the owner, version 14 of the study):
        // an INSET shadow, so not one pixel of height changes.
        '& .ssb-gifts{display:flex;align-items:center;gap:calc(var(--u)*1.5);background:rgba(255,255,255,.74);border-top:calc(var(--u)*.16) solid rgba(46,143,98,.3);box-shadow:inset 0 calc(var(--u)*-.16) 0 rgba(46,143,98,.3);padding:calc(var(--u)*3.4) calc(var(--u)*4.5)}',
        '& .ssb-gi{display:inline-flex;align-items:center;gap:calc(var(--u)*1.3);flex:0 0 auto;max-width:calc(var(--u)*30);min-width:0}',
        '& .ssb-gic{flex:none;display:block;color:#e4457c;width:calc(var(--u)*5.8);height:calc(var(--u)*5.8)}',
        '& .ssb-gi span{line-height:1.12;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
        '& .ssb-circs{display:flex;align-items:center;margin-inline-start:auto;min-width:0}',
        '& .ssb-c{position:relative;flex:none;border-radius:50%;background:#fff;border:calc(var(--u)*.45) solid #fff;width:calc(var(--u)*9);height:calc(var(--u)*9);margin-inline-start:calc(var(--u)*-1.3);box-shadow:0 calc(var(--u)*.4) calc(var(--u)*1.2) calc(var(--u)*-.3) rgba(42,34,40,.3);box-sizing:border-box}',
        '& .ssb-c:first-child{margin-inline-start:0}',
        '& .ssb-c img,.kbb-ph>.kbb-ph-i & .ssb-c img{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:none;object-fit:cover;border-radius:50%;background:#fff}',
        '& .ssb-free{display:inline-flex;align-items:center;flex:none;background:#eaf7f0;border:calc(var(--u)*.26) dashed #2e8f62;border-radius:var(--u);letter-spacing:.06em;text-transform:uppercase;padding:calc(var(--u)*.8) calc(var(--u)*1.8);margin-inline-start:calc(var(--u)*1.2);line-height:1.2}',
        // The on-page "Edit header" panel (page-header-editor.js) previews the
        // header's picture by inserting a <picture> beside this banner; while
        // the banner is on, that picture is not what the shop shows.
        '.kbb-ph>picture.kbb-ph-i:has(~ .kbb-ph-i>&){display:none}',
    ];

    /** The arch, as the approved design draws it. */
    private const ARCH = 'border-radius:48% 48% 0 0/70% 70% 0 0;background:radial-gradient(58% 40% at 50% 28%,#ffd9c6,transparent 70%),linear-gradient(160deg,#ffe7d6,#f6c9bd 60%,#e9aeb6)';

    /** The rectangle's ground, for a computer picture with a transparent background. */
    private const RECT = 'border-radius:calc(var(--u)*1.2);background:radial-gradient(70% 60% at 50% 30%,#ffd9c6,transparent 70%),linear-gradient(160deg,#ffe7d6,#f6c9bd 60%,#e9aeb6)';

    /** Phone only (900px and under, or the editor's phone preview). */
    private const PHONE = [
        '& .ssb-c:nth-child(n+6){display:none}',
        '&[dir=rtl] .ssb-copy{gap:calc(var(--u)*var(--ssb-gm,.9))}',
        '& .ssb-model{--ssb-fp:var(--ssb-fpm)}',
    ];

    /** Computer only (901px and wider, or the editor's computer preview). */
    private const DESK = [
        // 50px shorter than the first build at 1205 wide (the owner, version
        // 17): all of it from the hero -- 21.5u to 16.5u -- with the study's
        // padding and gap; the gift row keeps its height.
        // The copy is taller than the content box and is centred, so its
        // overflow is split evenly into the padding: 2.5u above and 2.3u below
        // hold it in English and Arabic (scrollHeight == clientHeight). Both
        // are 1.1u (11px at 1280) more than version 17 and the hero 2.2u
        // taller, so the room above tabby/tamara and below the coupon line
        // grows by about 11px each (the owner, 26 October: "give padding in
        // desktop ... above tabby / tamara, and below coupon code text").
        '& .ssb-hero{height:calc(var(--u)*18.7);gap:calc(var(--u)*2);padding:calc(var(--u)*2.5) calc(var(--u)*4) calc(var(--u)*2.3)}',
        '& .ssb-copy{gap:calc(var(--u)*var(--ssb-gd,.5));padding-bottom:0;align-items:var(--ssb-al-ad,stretch);text-align:var(--ssb-al-td,start)}',
        '& .ssb-pay{gap:calc(var(--u)*1.4);justify-content:var(--ssb-al-ad,flex-start)}& .ssb-pay svg{height:calc(var(--u)*3.2)}',
        '& .ssb-tag{line-height:1.15}& .ssb-coupon{margin-top:0;line-height:1.15}& .ssb-coupon b{padding:calc(var(--u)*.2) calc(var(--u)*1.6)}',
        // Arabic (Cairo) needs a taller coupon line than 1.15, and takes the
        // room from the gap (AR_DEFAULTS), so the shorter hero still holds it.
        '&[dir=rtl] .ssb-super{line-height:1}&[dir=rtl] .ssb-coupon{line-height:1.3}&[dir=rtl] .ssb-copy{gap:calc(var(--u)*var(--ssb-gd,.35))}',
        // She stands on the gift row's line, as on the phone.
        '& .ssb-model{width:calc(var(--u)*20);height:calc(100% + var(--u)*2.3);margin-bottom:calc(var(--u)*-2.3);--ssb-fp:var(--ssb-fpd)}',
        // "Rectangle design": his own wide picture (1440 x 700) fills a box
        // the full height of the hero's content, about 2:1, cover.
        '& .ssb-model.ssb-mode-rect{align-self:stretch;width:calc(var(--u)*34);height:auto;margin-bottom:0;overflow:hidden;border-radius:var(--u);background:#f6dfe7}',
        '& .ssb-mode-rect img,.kbb-ph>.kbb-ph-i & .ssb-mode-rect img{position:static;height:100%;object-fit:cover;object-position:var(--ssb-fpd,50% 50%)}',
        '& .ssb-mode-rect.ssb-fit-contain img,.kbb-ph>.kbb-ph-i & .ssb-mode-rect.ssb-fit-contain img{object-fit:contain}',
        '& .ssb-mode-rect::before{content:none}',
        // The gift row as the approved study drew it (the owner, 26 October:
        // "reverse the gifts row height to previously"); only the hairline
        // under it is new.
        '& .ssb-gifts{gap:calc(var(--u)*2);padding:calc(var(--u)*1.6) calc(var(--u)*4)}',
        '& .ssb-gi{gap:calc(var(--u)*1.1);max-width:calc(var(--u)*26)}',
        '& .ssb-gic{width:calc(var(--u)*4.3);height:calc(var(--u)*4.3)}',
        '& .ssb-c{width:calc(var(--u)*5.6);height:calc(var(--u)*5.6)}',
        '& .ssb-free{padding:calc(var(--u)*.55) calc(var(--u)*1.5);margin-inline-start:var(--u)}',
    ];

    /**
     * A picture sits one of three ways (SHAPES), chosen per device: cut into
     * the arch (a photo), the arch behind it, or nothing behind it -- a
     * transparent cutout stands on the banner, bottom-aligned, uncropped (the
     * owner: "remove the background dome"). `@` is the device letter.
     */
    private const SHAPE_RULES = [
        '& .ssb-sh@-clip{overflow:hidden;{ARCH}}',
        '& .ssb-sh@-clip img,.kbb-ph>.kbb-ph-i & .ssb-sh@-clip img{position:static;height:100%;object-fit:cover;object-position:var(--ssb-fp,50% 12%)}',
        '& .ssb-sh@-backdrop::before{content:"";position:absolute;inset:0;{ARCH}}',
        '& .ssb-sh@-backdrop img,& .ssb-sh@-none img,.kbb-ph>.kbb-ph-i & .ssb-sh@-backdrop img,.kbb-ph>.kbb-ph-i & .ssb-sh@-none img{position:absolute;inset:auto 0 0;height:100%;object-fit:contain;object-position:var(--ssb-fp,50% 100%)}',
        // Over the arch, a cutout may rise a little past its top for depth.
        '& .ssb-sh@-backdrop img,.kbb-ph>.kbb-ph-i & .ssb-sh@-backdrop img{height:106%}',
    ];

    private static ?string $css = null;

    /**
     * Every byte of CSS the banner prints on the page, built from the
     * constants above (rule 5: nothing here comes from a setting). The phone
     * rules apply at 900px and under, the computer rules above it -- each
     * written `:not()` the editor's force class for the other device, so the
     * editor's preview (cssForced()) can show either layout in any window.
     */
    public static function css(): string
    {
        if (self::$css !== null) {
            return self::$css;
        }

        [$phone, $desk] = self::deviceRules();

        return self::$css = '.kbb-ssb{container-type:inline-size;--u:3.9px;display:block;line-height:normal;min-width:0}'
            .'@media (min-width:901px){.kbb-ssb:not(.ssb-fm){--u:10px}}'
            .'@supports (width:min(1vw,1px)){.kbb-ssb{--u:min(1vw,3.9px)}@media (min-width:901px){.kbb-ssb:not(.ssb-fm){--u:min(.94vw,10px)}}}'
            .'@supports (width:min(1cqw,1px)){.kbb-ssb>.ssb-in{--u:min(1cqw,3.9px)}@media (min-width:901px){.kbb-ssb:not(.ssb-fm)>.ssb-in{--u:min(1cqw,10px)}}}'
            .self::at(self::BASE, '.kbb-ssb')
            .'@media (max-width:900px){'.self::at($phone, '.kbb-ssb:not(.ssb-fd)').'}'
            .'@media (min-width:901px){'.self::at($desk, '.kbb-ssb:not(.ssb-fm)').'}';
    }

    /**
     * The editor's device toggle: the phone layout under .ssb-fm and the
     * computer layout under .ssb-fd, whatever the window's width. Never on a
     * shop page -- the editor adds it to the page it opens on (and the
     * console to its own), so a shopper does not carry it.
     */
    public static function cssForced(): string
    {
        [$phone, $desk] = self::deviceRules();

        return '.kbb-ssb.ssb-fm{max-width:390px;margin-inline:auto}.kbb-ssb.ssb-fd{--u:10px}'
            .'@supports (width:min(1vw,1px)){.kbb-ssb.ssb-fd{--u:min(.94vw,10px)}}'
            .'@supports (width:min(1cqw,1px)){.kbb-ssb.ssb-fd>.ssb-in{--u:min(1cqw,10px)}}'
            .self::at($phone, '.kbb-ssb.ssb-fm').self::at($desk, '.kbb-ssb.ssb-fd');
    }

    /** @return array{0: list<string>, 1: list<string>} the phone's rules and the computer's, `&` for the root */
    private static function deviceRules(): array
    {
        $phone = self::PHONE;
        $desk = self::DESK;
        foreach (self::SHAPE_RULES as $r) {
            $phone[] = str_replace(['@', '{ARCH}'], ['m', self::ARCH], $r);
            $desk[] = str_replace(['@', '{ARCH}'], ['d', self::ARCH], $r);
        }
        foreach (self::ELEMENTS as $el => [, $sel, $sm, $sd, $w, $c, $disp, $vm, $vd]) {
            $rule = static fn (string $dev, float $size, bool $shown): string => '& '.$sel.'{font-size:calc(var(--u)*var(--ssb-'.$el.'-s'.$dev.','.self::num($size).'));'
                .'font-weight:var(--ssb-'.$el.'-w'.$dev.','.$w.');color:var(--ssb-'.$el.'-c'.$dev.','.$c.');'
                .'display:var(--ssb-'.$el.'-v'.$dev.','.($shown ? $disp : 'none').');'
                .'position:relative;left:var(--ssb-'.$el.'-x'.$dev.',0px);top:var(--ssb-'.$el.'-y'.$dev.',0px)}';
            $phone[] = $rule('m', $sm, $vm);
            $desk[] = $rule('d', $sd, $vd);
        }
        // The shipped Arabic computer title (AR_DEFAULTS), after the general rule.
        $desk[] = '&[dir=rtl] .ssb-super{font-size:calc(var(--u)*var(--ssb-title-sd,5.2))}';

        return [$phone, $desk];
    }

    /** Rules with `&` replaced by the root selector. */
    private static function at(array $rules, string $root): string
    {
        return implode('', array_map(static fn (string $r): string => str_replace('&', $root, $r), $rules));
    }

    /** The shape that ships: ON, because the owner approved this banner for this page. */
    public static function defaults(): array
    {
        $out = ['on' => true, 'code' => 'GLOW', 'logos' => true, 'model_on' => true, 'band' => 'all', 'gifts_on' => true];

        foreach (self::TEXTS as $k => [$en, $ar]) {
            $out[$k.'_en'] = $en;
            $out[$k.'_ar'] = $ar;
        }
        foreach (self::SLOTS as $slot => $_) {
            $out[$slot] = '';
        }
        $out['alpha'] = [];
        $out['mode_d'] = 'single';
        $out['shape_m'] = 'auto';
        $out['shape_d'] = 'auto';
        $out['fit_d'] = 'cover';
        foreach (self::FOCUS as $dev => [$x, $y]) {
            $out['fx_'.$dev] = $x;
            $out['fy_'.$dev] = $y;
        }
        $out['st'] = ['en' => [], 'ar' => []];

        return $out;
    }

    /**
     * The shipped value of one style property, per language and device: [el
     * => [s,c,w,x,y]] and '_' => [al,g]. Sizes and gaps in px at full width.
     */
    public static function styleDefaults(string $lang, string $dev): array
    {
        $cap = self::UCAP[$dev];
        $out = [];
        foreach (self::ELEMENTS as $el => [, , $sm, $sd, $w, $c, , $vm, $vd]) {
            $out[$el] = ['s' => round(($dev === 'm' ? $sm : $sd) * $cap, 2), 'w' => $w, 'c' => $c, 'x' => 0, 'y' => 0, 'v' => $dev === 'm' ? $vm : $vd];
        }
        $out['_'] = ['al' => 'start', 'g' => round(self::GAP[$dev] * $cap, 2)];

        if ($lang === 'ar') {
            foreach (self::AR_DEFAULTS[$dev] ?? [] as $el => $props) {
                $out[$el] = $props + $out[$el];
            }
        }

        return $out;
    }

    /**
     * Clean an incoming config. [clean, rejected]: with $strict anything wrong
     * is reported and the caller writes nothing; without it (the read path)
     * anything wrong quietly becomes its default, so a damaged row can never
     * take the page down.
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function sanitize(mixed $in, bool $strict = true): array
    {
        $in = is_array($in) ? $in : [];
        $d = self::defaults();
        $out = [];
        $rejected = [];

        foreach (['on', 'logos', 'model_on', 'gifts_on'] as $k) {
            $out[$k] = array_key_exists($k, $in) ? filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) : $d[$k];
        }

        foreach (self::TEXTS as $k => [, , $label]) {
            foreach (['en', 'ar'] as $lang) {
                // Present and emptied = the line is not drawn; absent = the default.
                $out[$k.'_'.$lang] = array_key_exists($k.'_'.$lang, $in) ? self::text($in[$k.'_'.$lang]) : $d[$k.'_'.$lang];
            }
        }

        $out['code'] = array_key_exists('code', $in) ? self::text($in['code'], 24) : $d['code'];

        foreach (self::SLOTS as $slot => [, , $label]) {
            $v = trim(is_scalar($in[$slot] ?? null) ? (string) $in[$slot] : '');
            if ($v !== '' && ! self::pictureOk($v)) {
                $rejected[$slot] = $label;
                $v = '';
            }
            $out[$slot] = $v;
        }

        // Written by the server (hasAlpha() on save); read back as booleans only.
        $out['alpha'] = [];
        foreach (self::SLOTS as $slot => $_) {
            if (! empty($in['alpha'][$slot] ?? false)) {
                $out['alpha'][$slot] = true;
            }
        }

        $mode = is_string($in['mode_d'] ?? null) ? $in['mode_d'] : 'single';
        if (! isset(self::MODES[$mode])) {
            $rejected['mode_d'] = 'Computer picture';
            $mode = 'single';
        }
        $out['mode_d'] = $mode;

        foreach (['m', 'd'] as $dev) {
            $v = is_string($in['shape_'.$dev] ?? null) ? $in['shape_'.$dev] : 'auto';
            if (! isset(self::SHAPES[$v])) {
                $rejected['shape_'.$dev] = 'Picture shape';
                $v = 'auto';
            }
            $out['shape_'.$dev] = $v;
            foreach (['fx', 'fy'] as $i => $k) {
                $out[$k.'_'.$dev] = self::clampInt($in[$k.'_'.$dev] ?? null, 0, 100, self::FOCUS[$dev][$i]);
            }
        }
        $fit = is_string($in['fit_d'] ?? null) ? $in['fit_d'] : 'cover';
        if (! isset(self::FITS[$fit])) {
            $rejected['fit_d'] = 'Picture fit';
            $fit = 'cover';
        }
        $out['fit_d'] = $fit;

        $band = is_string($in['band'] ?? null) ? $in['band'] : $d['band'];
        if (! isset(self::BANDS[$band]) && preg_match('/^range:[1-9]\d{0,9}$/', $band) !== 1) {
            $rejected['band'] = 'Which gifts';
            $band = $d['band'];
        }
        $out['band'] = $band;

        [$out['st'], $bad] = self::cleanStyle($in['st'] ?? null);
        foreach ($bad as $k => $label) {
            $rejected[$k] = $label;
        }

        return [$out, $strict ? $rejected : []];
    }

    /**
     * The stored style overrides, cleaned: st[lang][dev][el][prop]. Only keys
     * this class knows; anything wrong is reported and dropped (= inherited).
     *
     * @return array{0: array{en:array,ar:array}, 1: array<string,string>}
     */
    private static function cleanStyle(mixed $in): array
    {
        $out = ['en' => [], 'ar' => []];
        $bad = [];
        $in = is_array($in) ? $in : [];

        foreach (['en', 'ar'] as $lang) {
            foreach (['m', 'd'] as $dev) {
                $block = $in[$lang][$dev] ?? null;
                if (! is_array($block)) {
                    continue;
                }
                foreach ($block as $el => $props) {
                    $el = (string) $el;
                    if (! is_array($props) || ($el !== '_' && ! isset(self::ELEMENTS[$el]))) {
                        $bad["st.$lang.$dev.$el"] = 'Style';
                        continue;
                    }
                    foreach ($props as $p => $v) {
                        $p = (string) $p;
                        if ($v === null || $v === '') {
                            continue; // inherit
                        }
                        $clean = self::cleanProp($el, $p, $v);
                        if ($clean === null) {
                            $label = $el === '_' ? 'Copy block' : self::ELEMENTS[$el][0];
                            $bad["st.$lang.$dev.$el.$p"] = $label.' ('.strtoupper($lang).', '.($dev === 'd' ? 'computer' : 'phone').')';
                            continue;
                        }
                        $out[$lang][$dev][$el][$p] = $clean;
                    }
                }
            }
        }

        return [$out, $bad];
    }

    /** One style value, validated, or null. */
    private static function cleanProp(string $el, string $p, mixed $v): string|int|float|bool|null
    {
        if ($el === '_') {
            return match ($p) {
                'al' => is_string($v) && isset(self::ALIGNS[$v]) ? $v : null,
                'g' => is_numeric($v) && (float) $v >= self::RANGES['g'][0] && (float) $v <= self::RANGES['g'][1] ? round((float) $v, 2) : null,
                default => null,
            };
        }

        return match ($p) {
            'c' => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 && Color::isValidHex(strtoupper($v)) ? strtoupper($v) : null,
            'w' => is_numeric($v) && in_array((int) $v, self::WEIGHTS, true) && (float) $v === (float) (int) $v ? (int) $v : null,
            'v' => is_bool($v) || in_array($v, [0, 1, '0', '1'], true) ? (bool) $v : null,
            's', 'x', 'y' => is_numeric($v) && (float) $v >= self::RANGES[$p][0] && (float) $v <= self::RANGES[$p][1] ? round((float) $v, 2) : null,
            default => null,
        };
    }

    /**
     * One property as the page draws it: Arabic's own override, else the
     * English override, else the language's shipped value.
     */
    public static function resolve(array $st, string $lang, string $dev, string $el, string $p): mixed
    {
        $own = $st[$lang][$dev][$el][$p] ?? null;
        if ($own !== null) {
            return $own;
        }
        if ($lang === 'ar' && ($st['en'][$dev][$el][$p] ?? null) !== null) {
            return $st['en'][$dev][$el][$p];
        }

        return self::styleDefaults($lang, $dev)[$el][$p];
    }

    /**
     * The banner's style attribute for one language: a custom property for
     * every value that differs from the shipped one, and nothing else -- so
     * an untouched banner prints '' (no attribute). Built from validated
     * values only. resources/js/kbb/admin/super-sale-banner-editor.js
     * styleAttr() is this function in JavaScript; SuperSaleLiveBannerTest
     * runs both on the same configs and compares them.
     */
    public static function styleAttr(array $c, string $lang): string
    {
        $st = $c['st'] ?? ['en' => [], 'ar' => []];
        $parts = [];

        foreach (['m', 'd'] as $dev) {
            $def = self::styleDefaults($lang, $dev);
            foreach (self::ELEMENTS as $el => $_) {
                foreach (self::PROPS as $p) {
                    $v = self::resolve($st, $lang, $dev, $el, $p);
                    if ($p === 'v' ? (bool) $v === (bool) $def[$el][$p] : (string) $v === (string) $def[$el][$p]) {
                        continue;
                    }
                    $parts[] = '--ssb-'.$el.'-'.$p.$dev.':'.match ($p) {
                        's' => self::num((float) $v / self::UCAP[$dev]),
                        'x', 'y' => self::num((float) $v).'px',
                        'v' => $v ? self::ELEMENTS[$el][6] : 'none',
                        default => (string) $v,
                    };
                }
            }
            $al = self::resolve($st, $lang, $dev, '_', 'al');
            if ($al !== $def['_']['al']) {
                $parts[] = '--ssb-al-a'.$dev.':'.self::ALIGNS[$al][0];
                $parts[] = '--ssb-al-t'.$dev.':'.self::ALIGNS[$al][1];
            }
            $g = self::resolve($st, $lang, $dev, '_', 'g');
            if ((string) $g !== (string) $def['_']['g']) {
                $parts[] = '--ssb-g'.$dev.':'.self::num((float) $g / self::UCAP[$dev]);
            }
        }

        foreach (self::FOCUS as $dev => [$x, $y]) {
            if ((int) ($c['fx_'.$dev] ?? $x) !== $x || (int) ($c['fy_'.$dev] ?? $y) !== $y) {
                $parts[] = '--ssb-fp'.$dev.':'.(int) $c['fx_'.$dev].'% '.(int) $c['fy_'.$dev].'%';
            }
        }

        return implode(';', $parts);
    }

    /** The stored config, every key present. Read off the request's settings map: no query. */
    public static function config(array $all): array
    {
        $raw = $all[self::KEY] ?? null;

        return self::sanitize(is_array($raw) ? $raw : [], false)[0];
    }

    /**
     * What /super-sale/ draws, or null for "no banner -- the header picture as
     * before". Arabic when the request is Arabic.
     *
     * @return array<string,mixed>|null
     */
    public static function view(array $all, ?Request $request): ?array
    {
        $c = self::config($all);

        if (! $c['on']) {
            return null;
        }

        $ar = Locale::current() === 'ar';
        $lang = $ar ? 'ar' : 'en';
        $t = [];
        foreach (self::TEXTS as $k => $_) {
            $t[$k] = (string) $c[$k.'_'.$lang];
        }

        $gifts = [];
        if ($c['gifts_on']) {
            $gifts = self::giftsOf(app(FreeGifts::class)->bannerLines($request, $c['band']));
        }

        return [
            'ar' => $ar,
            't' => $t,
            'code' => (string) $c['code'],
            'logos' => (bool) $c['logos'],
            'tabby' => $c['logos'] ? self::sized(PaymentMarkArt::checkoutLogo('tabby'), 71, 28) : '',
            'tamara' => $c['logos'] ? self::sized(PaymentMarkArt::checkoutLogo('tamara', $ar), 85, 28) : '',
            'model' => $c['model_on'] ? self::pictures($c, $lang) : null,
            'gifts' => $gifts,
            'style' => self::styleAttr($c, $lang),
        ];
    }

    /** The gift photos the row draws. */
    public static function giftsOf(array $lines): array
    {
        $gifts = [];
        foreach (array_slice(FreeGifts::rows($lines), 0, self::MAX_GIFTS) as $g) {
            if ($g['image'] === '' || SafeUrl::src($g['image']) === '') {
                continue;
            }
            $gifts[] = [
                'src' => ImageVariants::variantUrl($g['image'], 200),
                'srcset' => self::srcset($g['image']),
                'alt' => trim($g['brand'].' '.$g['name']),
            ];
        }

        return $gifts;
    }

    /**
     * The pictures for one language, or null when there is none at all.
     *
     * The phone shows the SINGLE picture (Arabic's own, else English's, else
     * the shipped cutout), and so does the computer in "Single image" mode.
     * In "Rectangle design" mode the computer shows his wide picture instead
     * -- or, with none uploaded, the single picture as before. Automatic
     * shape: a transparent picture stands on the banner, a photo is cut into
     * the arch.
     *
     * @return array{m:array,d:?array,two:bool,cls:string}|null
     */
    public static function pictures(array $c, string $lang): ?array
    {
        $slot = $lang === 'ar' && $c['model_ar'] !== '' ? 'model_ar' : ($c['model'] !== '' ? 'model' : '');
        $m = self::picture($c, $slot);
        if ($m === null) {
            return null;
        }

        $d = null;
        if ($c['mode_d'] === 'rect') {
            $rslot = $lang === 'ar' && $c['model_d_ar'] !== '' ? 'model_d_ar' : ($c['model_d'] !== '' ? 'model_d' : '');
            $d = $rslot === '' ? null : self::picture($c, $rslot);
        }

        $shape = static function (string $dev) use ($c, $m): string {
            $v = $c['shape_'.$dev];

            return $v === 'auto' ? ($m['alpha'] ? 'none' : 'clip') : $v;
        };

        $cls = 'ssb-shm-'.$shape('m');
        $cls .= $d === null ? ' ssb-shd-'.$shape('d') : ' ssb-mode-rect'.($c['fit_d'] === 'contain' ? ' ssb-fit-contain' : '');

        return ['m' => $m, 'd' => $d, 'two' => $d !== null, 'cls' => $cls];
    }

    /** One slot's picture ('' = the shipped cutout): src, srcset, url, alpha -- or null. */
    private static function picture(array $c, string $slot): ?array
    {
        $url = self::modelUrl($slot === '' ? '' : (string) $c[$slot]);
        if ($url === '') {
            return null;
        }

        $set = self::srcset($url);
        if ($set !== '' && $slot === '') {
            // The shipped cutout is 381px wide, so its only copy is the 200w
            // one; the original goes in the list too, or a 2x screen would be
            // handed the 200px file. (A big upload has 400w and 800w.)
            $set .= ', '.$url.' '.self::SHIPPED[1].'w';
        }

        return [
            'src' => ImageVariants::variantUrl($url, 400),
            'srcset' => $set,
            'url' => $url,
            'alpha' => $slot === '' ? self::SHIPPED[3] : ! empty($c['alpha'][$slot]),
        ];
    }

    /** A picture's address: the owner's own, or the shipped cutout when the file is there, or ''. */
    public static function modelUrl(string $stored): string
    {
        $stored = trim($stored);

        if ($stored !== '') {
            return self::pictureOk($stored) ? $stored : '';
        }

        return is_file(public_path(self::MODEL_REL)) ? Media::urlFor(self::MODEL_REL) : '';
    }

    /** http(s) on the web, or a path on this shop; nothing that could break out of an attribute. */
    public static function pictureOk(string $v): bool
    {
        return strlen($v) <= 500
            && preg_match('/[\x00-\x20"\'<>`\\\\]/', $v) !== 1
            && ((str_starts_with($v, '/') && ! str_starts_with($v, '//')) || (preg_match('#^https?://[^/?\#@\s]#i', $v) === 1 && SafeUrl::src($v) !== ''));
    }

    /**
     * Does this picture have a transparent background? Asked ONCE, on save,
     * for a file on this shop's own disk (an address elsewhere is never
     * fetched: false). The file header says whether there is an alpha channel
     * at all (PNG colour type 4/6 or a tRNS chunk; WebP VP8X's alpha flag or
     * VP8L's alpha bit); then, where GD can open it, a corner pixel must
     * actually be see-through -- a photo saved with an unused alpha channel is
     * still a photo.
     */
    public static function hasAlpha(string $url): bool
    {
        $file = self::localFile($url);
        if ($file === null) {
            return false;
        }

        $head = (string) @file_get_contents($file, false, null, 0, 65536);
        $type = null;

        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $type = 'png';
            $color = ord($head[25] ?? "\0");
            $idat = strpos($head, 'IDAT');
            $trns = strpos($head, 'tRNS');
            $flag = $color === 4 || $color === 6 || ($trns !== false && ($idat === false || $trns < $idat));
        } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            $type = 'webp';
            $chunk = substr($head, 12, 4);
            $flag = match ($chunk) {
                'VP8X' => (ord($head[20] ?? "\0") & 0x10) !== 0,
                'VP8L' => ((unpack('V', substr($head, 21, 4) ?: "\0\0\0\0")[1] ?? 0) >> 28 & 1) === 1,
                default => false,
            };
        } else {
            return false;
        }

        if (! $flag) {
            return false;
        }

        $im = null;
        try {
            if ($type === 'png' && function_exists('imagecreatefrompng')) {
                $im = @imagecreatefrompng($file);
            } elseif ($type === 'webp' && function_exists('imagecreatefromwebp')) {
                $im = @imagecreatefromwebp($file);
            }
        } catch (\Throwable) {
            $im = null;
        }

        if (! $im instanceof \GdImage) {
            return true; // the header said so and GD cannot say otherwise
        }

        $w = imagesx($im);
        $h = imagesy($im);
        $clear = false;
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1], [intdiv($w, 2), 0]] as [$x, $y]) {
            if (((imagecolorat($im, max(0, $x), max(0, $y)) >> 24) & 0x7F) >= 64) {
                $clear = true;
                break;
            }
        }
        imagedestroy($im);

        return $clear;
    }

    /** A picture address on this shop's own disk, as a file path inside the public root, or null. */
    private static function localFile(string $url): ?string
    {
        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $own = array_filter([
                parse_url((string) config('app.url'), PHP_URL_HOST),
                parse_url((string) (\App\Models\Setting::map()['site_url'] ?? ''), PHP_URL_HOST),
                app()->bound('request') ? request()->getHost() : null,
            ]);
            if (! in_array(strtolower($host), array_map('strtolower', $own), true)) {
                return null;
            }
        }

        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $base = rtrim((string) parse_url(Url::base(), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }
        if (! str_starts_with($path, '/uploads/') && ! str_starts_with($path, '/wp-content/uploads/')) {
            return null;
        }
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        $root = realpath(public_path());
        $file = realpath(public_path(ltrim($path, '/')));

        return $root !== false && $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /**
     * What the on-page editor and the console's Super Sale banner tab work
     * from: the stored config, the shipped values, the spec of every control,
     * the gifts each band would show (ONE product query, admin-only), and the
     * endpoints. Never part of any shop page.
     */
    public static function editorContext(array $all, ?Request $request): array
    {
        $texts = [];
        foreach (self::TEXTS as $k => [$en, $ar, $label]) {
            $texts[] = ['key' => $k, 'label' => $label, 'en' => $en, 'ar' => $ar];
        }

        $gifts = app(FreeGifts::class);
        $bands = [];
        foreach (self::BANDS as $value => $label) {
            $bands[] = ['value' => $value, 'label' => $label];
        }
        foreach ($gifts->ranges() as $r) {
            $bands[] = ['value' => 'range:'.$r['id'], 'label' => 'Only the range '.FreeGifts::rangeLabel($r)];
        }
        $giftsFor = [];
        foreach ($gifts->bannerChoices($request, array_column($bands, 'value')) as $band => $lines) {
            $giftsFor[$band] = self::giftsOf($lines);
        }

        $elements = [];
        foreach (self::ELEMENTS as $el => [$label]) {
            $elements[] = ['key' => $el, 'label' => $label];
        }
        $defaults = [];
        foreach (['en', 'ar'] as $lang) {
            foreach (['m', 'd'] as $dev) {
                $defaults[$lang][$dev] = self::styleDefaults($lang, $dev);
            }
        }

        return [
            'config' => self::forJson(self::config($all)),
            'defaults' => self::forJson(self::defaults()),
            'texts' => $texts,
            'bands' => $bands,
            'gifts' => $giftsFor,
            'gifts_mode' => $gifts->mode(),
            'spec' => [
                'elements' => $elements,
                'style_defaults' => $defaults,
                'ranges' => self::RANGES,
                'weights' => self::WEIGHTS,
                'aligns' => array_map(static fn (array $a) => $a[2], self::ALIGNS),
                'align_css' => array_map(static fn (array $a) => [$a[0], $a[1]], self::ALIGNS),
                'ucap' => self::UCAP,
                'slots' => array_map(static fn (array $s) => ['kind' => $s[0], 'lang' => $s[1], 'label' => $s[2]], self::SLOTS),
                'element_display' => array_map(static fn (array $e) => $e[6], self::ELEMENTS),
                'shapes' => self::SHAPES,
                'modes' => self::MODES,
                'rect_size' => self::RECT_SIZE,
                'fits' => self::FITS,
                'focus' => self::FOCUS,
                'max_text' => self::MAX_TEXT,
                'max_gifts' => self::MAX_GIFTS,
                'css' => self::css(),
                'css_forced' => self::cssForced(),
                'gift_icon' => self::GIFT_ICON,
                'logos' => [
                    'tabby' => self::sized(PaymentMarkArt::checkoutLogo('tabby'), 71, 28),
                    'tamara' => self::sized(PaymentMarkArt::checkoutLogo('tamara'), 85, 28),
                    'tamara_ar' => self::sized(PaymentMarkArt::checkoutLogo('tamara', true), 85, 28),
                ],
            ],
            'shipped' => ['url' => self::modelUrl(''), 'alpha' => self::SHIPPED[3], 'w' => self::SHIPPED[1]],
            'endpoints' => [
                'save' => Url::to('/admin-api/page-banners'),
                'context' => Url::to('/admin-api/storefront/context'),
                'media' => Url::to('/admin-api/media'),
                'upload' => Url::to('/admin-api/media/upload'),
            ],
            'upload' => [
                'folder' => 'appearance',
                'max_bytes' => \App\Http\Controllers\Admin\StorefrontAdminController::UPLOAD_MAX_BYTES,
                'types' => \App\Http\Controllers\Admin\StorefrontAdminController::UPLOAD_TYPES,
            ],
            'url' => Url::to('/super-sale/'),
            'url_ar' => Url::to('/ar/super-sale/'),
            'console' => (string) (parse_url(route('admin'), PHP_URL_PATH) ?: '/').'#pagebanners',
            'editor' => self::editorModule(),
        ];
    }

    /**
     * A config as the editors receive it: every level of the style map, and
     * the transparency flags, as JSON objects even when empty. PHP encodes an
     * empty array as [], a property set on a JavaScript array is lost on the
     * way back, and the console's draft registry would see the change of
     * shape as an unsaved edit.
     */
    public static function forJson(array $c): array
    {
        $st = [];
        foreach (['en', 'ar'] as $lang) {
            $devs = [];
            foreach ($c['st'][$lang] ?? [] as $dev => $els) {
                $devs[$dev] = (object) array_map(static fn (array $props) => (object) $props, $els);
            }
            $st[$lang] = (object) $devs;
        }
        $c['st'] = (object) $st;
        $c['alpha'] = (object) ($c['alpha'] ?? []);

        return $c;
    }

    /** The editor module's built address (the console imports it by this), or null before an asset build. */
    public static function editorModule(): ?string
    {
        try {
            $url = \Illuminate\Support\Facades\Vite::asset('resources/js/kbb/admin/super-sale-banner-editor.js');
        } catch (\Throwable) {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
    }

    /** A number as CSS prints it: no trailing zeros, no exponent. */
    public static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private static function clampInt(mixed $v, int $min, int $max, int $default): int
    {
        return is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : $default;
    }

    /** One line of plain text: no tags, no control characters, clipped. */
    private static function text(mixed $v, int $max = self::MAX_TEXT): string
    {
        $v = is_scalar($v) ? (string) $v : '';
        $v = strip_tags($v);
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));

        return mb_substr($v, 0, $max);
    }

    private static function srcset(string $url): string
    {
        try {
            return ImageVariants::srcsetFor($url);
        } catch (\Throwable) {
            return '';
        }
    }

    /** A constant SVG with its own size, so a browser that drops the CSS still draws it small. */
    private static function sized(string $svg, int $w, int $h): string
    {
        return $svg === '' ? '' : (string) preg_replace('/^<svg /', '<svg width="'.$w.'" height="'.$h.'" ', $svg, 1);
    }
}
